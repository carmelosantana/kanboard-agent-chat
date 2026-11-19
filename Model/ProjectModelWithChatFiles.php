<?php

namespace Kanboard\Plugin\AgentChat\Model;

use Kanboard\Model\ProjectModel;

/**
 * Kanboard's ProjectModel, plus removal of the project's chat images from objectStorage.
 *
 * Kanboard fires no event when a project is removed, and the database cascade deletes the
 * agentchat_files rows with it, so the blob keys are read first; the blobs are removed once the
 * project is gone (a removal that fails leaves them in place). Plugin::initialize() installs it
 * as `projectModel` only when that service is still core's own class.
 */
class ProjectModelWithChatFiles extends ProjectModel
{
    public function remove($project_id)
    {
        $files = new FileModel($this->container);
        $keys = $files->keysForProject((int) $project_id);
        $removed = parent::remove($project_id);
        if ($removed) {
            $files->removeBlobs($keys);
        }
        return $removed;
    }
}
