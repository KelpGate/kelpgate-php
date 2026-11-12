<?php

// Router for PHP's built-in web server: echoes what the client sent, so the cURL transport can be tested
// against a real HTTP server.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($path === '/redirect') {
    header('Location: /elsewhere', true, 302);
    exit;
}
if ($path === '/slow') {
    sleep(3);
    echo '{}';
    exit;
}
if ($path === '/teapot') {
    http_response_code(418);
    header('X-Request-Id: req-teapot');
    echo json_encode(['error' => 'short and stout']);
    exit;
}
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'fields' => $_POST,
    'file' => isset($_FILES['file']) ? [
        'name' => $_FILES['file']['name'],
        'size' => $_FILES['file']['size'],
        'contents' => file_get_contents($_FILES['file']['tmp_name']),
    ] : null,
    'body' => file_get_contents('php://input'),
]);
