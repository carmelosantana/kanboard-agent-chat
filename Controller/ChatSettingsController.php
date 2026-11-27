<?php

namespace Kanboard\Plugin\AgentChat\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\AgentChat\Model\AccessPolicy;
use Kanboard\Plugin\AgentChat\Model\AiGate;
use Kanboard\Plugin\AgentChat\Model\LimitsSettings;
use Kanboard\Plugin\AgentChat\Model\ProjectSettings;

/**
 * AgentChat's settings pages, plain forms that post back and redirect (PRG).
 *
 *  - project / saveProject (project managers and app admins, AccessPolicy::canManage): the
 *    bot on or off, who may ask it, and whether it sees images. The bot's model is not set
 *    here: AiConnector's model routing picks it (agentchat.bot), so a posted ai_profile is
 *    ignored.
 *  - limits / saveLimits (app admin): every LimitsSettings value, each a whole number of 1
 *    or more.
 *
 * A refused save stores nothing and answers 400 with the form and its errors. Values are read
 * from storage on each request, so a change applies to the next one.
 */
class ChatSettingsController extends BaseController
{
    public function project(array $values = [], array $errors = [], int $status = 200): void
    {
        $project = $this->managedProject();
        $settings = new ProjectSettings($this->container, (int) $project['id']);
        $values += [
            'bot_enabled' => $settings->botEnabled(),
            'ask_role' => $settings->askRole(),
            'bot_images' => $settings->botImages(),
        ];

        $this->response->html($this->helper->layout->project('AgentChat:settings/project', [
            'project' => $project,
            'title' => t('Chat'),
            'values' => $values,
            'errors' => $errors,
            'botReady' => AiGate::isReadyFor($this->container, (int) $project['id']),
        ]), $status);
    }

    public function saveProject(): void
    {
        $project = $this->managedProject();
        $this->checkCSRFForm();
        $askRole = $this->request->getRawValue('ask_role');
        $values = [
            'bot_enabled' => $this->request->getRawValue('bot_enabled') === '1',
            'ask_role' => is_string($askRole) ? $askRole : '',
            'bot_images' => $this->request->getRawValue('bot_images') === '1',
        ];

        $errors = [];
        if (! in_array($values['ask_role'], ProjectSettings::ASK_ROLES, true)) {
            $errors['ask_role'] = [t('Choose who may ask the bot.')];
        }
        if ($errors !== []) {
            $this->project($values, $errors, 400);
            return;
        }

        $settings = new ProjectSettings($this->container, (int) $project['id']);
        $settings->setBotEnabled($values['bot_enabled']);
        $settings->setAskRole($values['ask_role']);
        $settings->setBotImages($values['bot_images']);
        $this->flash->success(t('Settings saved successfully.'));
        $this->response->redirect($this->helper->url->to('ChatSettingsController', 'project', ['project_id' => $project['id'], 'plugin' => 'AgentChat']));
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
        $this->response->redirect($this->helper->url->to('ChatSettingsController', 'limits', ['plugin' => 'AgentChat']));
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
}
