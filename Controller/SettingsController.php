<?php

namespace Kanboard\Plugin\AgentChat\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Model\AccessPolicy;
use Kanboard\Plugin\AgentChat\Model\AiGate;
use Kanboard\Plugin\AgentChat\Model\LimitsSettings;
use Kanboard\Plugin\AgentChat\Model\ProjectSettings;
use Kanboard\Plugin\AiConnector\Model\ProviderRegistry;

/**
 * AgentChat's settings pages, plain forms that post back and redirect (PRG).
 *
 *  - project / saveProject (project managers and app admins, AccessPolicy::canManage): the
 *    bot on or off, who may ask it, its AiConnector profile, and whether it sees images.
 *    The profile must be one of ProviderRegistry::listProfiles() ids, or empty for
 *    AiConnector's default; without AiConnector the field is not offered and a stored
 *    profile is left as it is.
 *  - limits / saveLimits (app admin): every LimitsSettings value, each a whole number of 1
 *    or more.
 *
 * A refused save stores nothing and answers 400 with the form and its errors. Values are read
 * from storage on each request, so a change applies to the next one.
 */
class SettingsController extends BaseController
{
    public function project(array $values = [], array $errors = [], int $status = 200): void
    {
        $project = $this->managedProject();
        $settings = new ProjectSettings($this->container, (int) $project['id']);
        $values += [
            'bot_enabled' => $settings->botEnabled(),
            'ask_role' => $settings->askRole(),
            'ai_profile' => (string) $settings->aiProfile(),
            'bot_images' => $settings->botImages(),
        ];

        $this->response->html($this->helper->layout->project('AgentChat:settings/project', [
            'project' => $project,
            'title' => t('Chat'),
            'values' => $values,
            'errors' => $errors,
            'profiles' => $this->profiles(),
            'botReady' => AiGate::isReady($this->container),
        ]), $status);
    }

    public function saveProject(): void
    {
        $project = $this->managedProject();
        $this->checkCSRFForm();
        $profiles = $this->profiles();
        $askRole = $this->request->getRawValue('ask_role');
        $profile = $this->request->getRawValue('ai_profile') ?? '';
        $values = [
            'bot_enabled' => $this->request->getRawValue('bot_enabled') === '1',
            'ask_role' => is_string($askRole) ? $askRole : '',
            'ai_profile' => is_string($profile) ? $profile : '',
            'bot_images' => $this->request->getRawValue('bot_images') === '1',
        ];

        $errors = [];
        if (! in_array($values['ask_role'], ProjectSettings::ASK_ROLES, true)) {
            $errors['ask_role'] = [t('Choose who may ask the bot.')];
        }
        if ($profiles !== null && (! is_string($profile) || ($profile !== '' && ! array_key_exists($profile, $profiles)))) {
            $errors['ai_profile'] = [t('Choose one of the AI profiles.')];
        }
        if ($errors !== []) {
            $this->project($values, $errors, 400);
            return;
        }

        $settings = new ProjectSettings($this->container, (int) $project['id']);
        $settings->setBotEnabled($values['bot_enabled']);
        $settings->setAskRole($values['ask_role']);
        $settings->setBotImages($values['bot_images']);
        if ($profiles !== null) {
            $settings->setAiProfile($values['ai_profile']);
        }
        $this->flash->success(t('Settings saved successfully.'));
        $this->response->redirect($this->helper->url->to('SettingsController', 'project', ['project_id' => $project['id'], 'plugin' => 'AgentChat']));
    }

    public function limits(array $values = [], array $errors = [], int $status = 200): void
    {
        $this->assertAppAdmin();
        $this->response->html($this->helper->layout->config('AgentChat:settings/global', [
            'title' => t('Settings').' &gt; '.t('Chat limits'),
            'values' => $values + (new LimitsSettings($this->container))->values(),
            'errors' => $errors,
        ]), $status);
    }

    public function saveLimits(): void
    {
        $this->assertAppAdmin();
        $this->checkCSRFForm();
        $posted = [];
        foreach (array_keys(LimitsSettings::FIELDS) as $key) {
            $posted[$key] = $this->request->getRawValue($key);
        }

        $errors = (new LimitsSettings($this->container))->save($posted);
        if ($errors !== []) {
            $this->limits(array_map(fn ($v) => is_string($v) ? $v : '', $posted), array_map(fn ($e) => [$e], $errors), 400);
            return;
        }
        $this->flash->success(t('Settings saved successfully.'));
        $this->response->redirect($this->helper->url->to('SettingsController', 'limits', ['plugin' => 'AgentChat']));
    }

    private function managedProject(): array
    {
        $project = $this->getProject();
        if (! (new AccessPolicy($this->container))->canManage((int) $this->userSession->getId(), (int) $project['id'])) {
            throw new AccessForbiddenException();
        }
        return $project;
    }

    private function assertAppAdmin(): void
    {
        if ($this->userSession->getRole() !== Role::APP_ADMIN) {
            throw new AccessForbiddenException();
        }
    }

    /** @return ?array<string, string> profile labels by id, or null without AiConnector */
    private function profiles(): ?array
    {
        if (! AiGate::connectorPresent($this->container)) {
            return null;
        }
        try {
            $profiles = [];
            foreach ((new ProviderRegistry($this->container))->listProfiles() as $p) {
                $profiles[(string) $p['id']] = (string) $p['label'].' ('.$p['model'].')';
            }
            return $profiles;
        } catch (\Throwable $e) {
            $this->logger->error('AgentChat: AI profiles not listed: '.$e->getMessage());
            return null;
        }
    }
}
