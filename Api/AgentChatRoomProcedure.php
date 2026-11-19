<?php

namespace Kanboard\Plugin\AgentChat\Api;

use Kanboard\Api\Procedure\BaseProcedure;
use Kanboard\Plugin\AgentChat\Model\RoomApi;

// JSON-RPC: post to and read a project's chat room as the token's user. Holds ONLY RPC methods
// (every method on a withObject instance is remotely callable, private ones included) and
// declares params without type hints (a TypeError is a fatal with no JSON-RPC body). Access,
// validation and error mapping live in RoomApi.
class AgentChatRoomProcedure extends BaseProcedure
{
    public function chatSendMessage($project_id, $body, $client_msg_id = '')
    {
        return (new RoomApi($this->container))->send($project_id, $body, $client_msg_id);
    }

    public function chatRoomMessages($project_id, $after = 0, $limit = 50)
    {
        return (new RoomApi($this->container))->read($project_id, $after, $limit);
    }
}
