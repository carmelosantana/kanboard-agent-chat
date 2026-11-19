<?php
/**
 * Pushes a Save-it style JSON file into a project's AgentChat knowledge over Kanboard's JSON-RPC
 * API (knowledgeUpsert), the way a transcript agent would. Dev and test helper; no dependencies.
 *
 *   KANBOARD_URL=http://localhost:8081/jsonrpc.php \
 *   KANBOARD_USER=archive.manager KANBOARD_TOKEN=<personal API token> \
 *   php scripts/import-saveit-fixture.php <project_id> [file.json]
 *
 * The user must manage the project (or be an app admin). With KANBOARD_USER=jsonrpc and the
 * application token, any existing project is accepted. The file defaults to
 * Test/fixtures/saveit-sample.json: {"source": "save-it", "items": [{"external_id", "title",
 * "body", "url", "author", "occurred_at", "metadata"}, ...]}. Re-running updates in place.
 */

$url = getenv('KANBOARD_URL') ?: 'http://localhost:8081/jsonrpc.php';
$user = getenv('KANBOARD_USER') ?: '';
$token = getenv('KANBOARD_TOKEN') ?: '';
$projectId = (int) ($argv[1] ?? 0);
$file = $argv[2] ?? __DIR__.'/../Test/fixtures/saveit-sample.json';

if ($projectId < 1 || $user === '' || $token === '') {
    fwrite(STDERR, "Usage: KANBOARD_USER=… KANBOARD_TOKEN=… [KANBOARD_URL=…] php {$argv[0]} <project_id> [file.json]\n");
    exit(2);
}
$data = json_decode((string) @file_get_contents($file), true);
if (! is_array($data) || ! is_string($data['source'] ?? null) || ! is_array($data['items'] ?? null)) {
    fwrite(STDERR, "Cannot read a {source, items[]} document from $file\n");
    exit(2);
}

$failed = 0;
foreach ($data['items'] as $n => $item) {
    $params = ['project_id' => $projectId, 'source' => $data['source']] + array_intersect_key(
        (array) $item,
        array_flip(['external_id', 'title', 'body', 'url', 'author', 'occurred_at', 'metadata'])
    );
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Basic ".base64_encode("$user:$token")."\r\n",
        'content' => json_encode(['jsonrpc' => '2.0', 'method' => 'knowledgeUpsert', 'id' => $n + 1, 'params' => $params]),
        'ignore_errors' => true,
        'timeout' => 30,
    ]]);
    $raw = @file_get_contents($url, false, $context);
    $reply = is_string($raw) ? json_decode($raw, true) : null;
    $label = (string) ($item['external_id'] ?? "#$n");

    if (isset($reply['result']['id'])) {
        echo "ok    $label -> knowledge #{$reply['result']['id']}\n";
        continue;
    }
    $failed++;
    $error = $reply['error'] ?? null;
    $why = is_array($error) ? trim(($error['message'] ?? '').' '.(is_string($error['data'] ?? null) ? $error['data'] : '')) : 'no JSON-RPC reply from '.$url;
    echo "FAIL  $label: $why\n";
}
exit($failed === 0 ? 0 : 1);
