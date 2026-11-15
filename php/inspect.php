<?php

declare(strict_types=1);

/**
 * Terminal-only tool: prints the structure of the grade report and home pages for the most
 * recently active logged-in session, with letter grades and GPA values masked.
 *
 * Usage: php inspect.php [grades|home]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/src/bootstrap.php';

$config = wcu_config();
$db = $config['db'];
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'] ?? 'localhost', $db['name']),
    $db['user'],
    $db['password']
);
$row = $pdo->query('SELECT portal_session FROM wcu_users WHERE portal_expires_at > UNIX_TIMESTAMP() ORDER BY updated_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$state = $row ? json_decode((string) (new Crypto($config['secret_key']))->decrypt($row['portal_session']), true) : null;
if (!is_array($state)) {
    exit("No active session. Log in to the bot first, then run this within 30 minutes.\n");
}

$client = PortalClient::fromState($state);
$request = new ReflectionMethod(PortalClient::class, 'request');
$request->setAccessible(true);

function mask(string $text): string
{
    $text = (string) preg_replace('/(?<![A-Za-z])(A\+|A-|A|B\+|B-|B|C\+|C-|C|D\+|D-|D|F|NG|IA|P|NP)(?![A-Za-z+\-])/', '<G>', $text);
    return (string) preg_replace('/\b\d\.\d{1,2}\b/', '<#>', $text);
}

function walk(DOMNode $node, int $depth, array &$out): void
{
    foreach ($node->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }
        $tag = strtolower($child->nodeName);
        if (in_array($tag, ['script', 'style', 'nav', 'footer', 'head', 'select'], true)) {
            continue;
        }
        $class = trim($child->getAttribute('class'));
        $label = $tag . ($class !== '' ? '.' . str_replace(' ', '.', $class) : '');
        if ($tag === 'tr') {
            $cells = [];
            foreach ($child->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $span = $cell->getAttribute('colspan');
                    $cells[] = strtolower($cell->nodeName) . ($span !== '' ? "[{$span}]" : '') . ':' . mask(Html::text($cell, ' '));
                }
            }
            $out[] = str_repeat(' ', $depth) . 'tr  ' . implode(' | ', $cells);
            continue;
        }
        if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'caption', 'legend', 'label', 'p', 'strong', 'b', 'dt', 'dd', 'li'], true)
            || ($tag === 'div' && preg_match('/head|title|header/i', $class))) {
            $text = mask(Html::text($child, ' '));
            if ($text !== '') {
                $out[] = str_repeat(' ', $depth) . $label . '  ' . Text::slice($text, 160);
                continue;
            }
        }
        if ($tag === 'table') {
            $out[] = str_repeat(' ', $depth) . $label;
        }
        walk($child, $depth + ($tag === 'table' ? 1 : 0), $out);
    }
}

$page = ($argv[1] ?? 'grades') === 'home' ? 'Home' : 'Grade/GradeReport';
$response = $request->invoke($client, PortalClient::BASE_URL . $page);
echo "URL: {$response['url']}  HTTP {$response['status']}\n";
[$dom] = Html::load($response['body']);
$body = $dom->getElementsByTagName('body')->item(0);
$out = [];
walk($body ?? $dom, 0, $out);
echo implode("\n", $out), "\n";
