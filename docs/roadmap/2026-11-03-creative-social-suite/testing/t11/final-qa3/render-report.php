<?php

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

// Report-only conversion; does not bootstrap the application or read .env.
require getcwd().'/vendor/autoload.php';
$environment = new Environment(['html_input' => 'strip']);
$environment->addExtension(new CommonMarkCoreExtension);
$environment->addExtension(new TableExtension);
$converter = new MarkdownConverter($environment);
$root = __DIR__;
$theme = file_get_contents('C:/Users/daycr/.codex/plugins/cache/daycry/custom-agents/1.21.1/skills/to-pdf/assets/theme.css');
$body = $converter->convert(file_get_contents($root.'/report.md'));
file_put_contents($root.'/report.html', '<!doctype html><html lang="es"><meta charset="utf-8"><title>QA independiente T11 - intento3</title><style>'.$theme.' img {max-height:245mm;object-fit:contain} img[src$="composite-mobile.png"] {width:80mm;height:173mm;object-fit:cover;object-position:top} pre {white-space:pre-wrap;overflow-wrap:anywhere} h2,h3 {break-after:avoid} table {font-size:8.5pt;break-inside:auto;table-layout:fixed;width:100%} td,th {overflow-wrap:anywhere} tr {break-inside:avoid} </style><body><main class="doc">'.$body.'</main></body></html>');
