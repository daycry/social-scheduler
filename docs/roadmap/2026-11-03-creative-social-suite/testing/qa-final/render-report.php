<?php
// Report-only conversion; does not bootstrap the application or read .env.
require getcwd().'/vendor/autoload.php';
$environment = new League\CommonMark\Environment\Environment(['html_input' => 'strip']);
$environment->addExtension(new League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());
$environment->addExtension(new League\CommonMark\Extension\Table\TableExtension());
$converter = new League\CommonMark\MarkdownConverter($environment);
$root = __DIR__;
$theme = file_get_contents('C:/Users/daycr/.codex/plugins/cache/daycry/custom-agents/1.21.1/skills/to-pdf/assets/theme.css');
$body = $converter->convert(file_get_contents($root.'/report.md'));
file_put_contents($root.'/report.html', '<!doctype html><html lang="es"><meta charset="utf-8"><title>QA final suite creativa</title><style>'.$theme.' img {max-height:245mm;object-fit:contain} pre {white-space:pre-wrap;overflow-wrap:anywhere} h2,h3 {break-after:avoid} table {font-size:9pt}</style><body><main class="doc">'.$body.'</main></body></html>');
