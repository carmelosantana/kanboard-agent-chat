<?php

namespace Kanboard\Plugin\AgentChat\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\AgentChat\Helper\KanboardHost;
use Kanboard\Plugin\AgentChat\Model\AccessPolicy;
use Kanboard\Plugin\AgentChat\Model\KnowledgeModel;

/**
 * Read-only viewer for one knowledge document (the bot's citations link here). The route has
 * no project_id, so access is checked against the document's own project after loading it.
 */
class KnowledgeController extends BaseController
{
    public function show(): void
    {
        $doc = (new KnowledgeModel($this->container))->getById($this->request->getIntegerParam('id'));
        if ($doc === null) {
            // Core's PageNotFoundException renders this page with status 200.
            $this->response->html($this->helper->layout->app('app/notfound', ['title' => t('Page not found')]), 404);
            return;
        }
        if (! (new AccessPolicy($this->container))->canView((int) $this->userSession->getId(), (int) $doc['project_id'])) {
            throw new AccessForbiddenException();
        }
        $project = $this->projectModel->getById((int) $doc['project_id']);
        $url = (string) $doc['url'];

        $this->response->html($this->helper->layout->app('AgentChat:knowledge/show', [
            'title' => $project['name'].' &gt; '.$doc['title'], // the layout escapes it
            'project' => $project,
            'doc' => $doc,
            'url' => KnowledgeModel::isHttpUrl($url) ? $url : '',
            'body' => (new KanboardHost($this->container))->markdown((string) $doc['body']),
        ]));
    }
}
