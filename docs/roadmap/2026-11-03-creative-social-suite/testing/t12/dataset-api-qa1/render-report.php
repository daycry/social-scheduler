<?php
declare(strict_types=1);
// Report conversion only. No app bootstrap or real .env.
require getcwd().'/vendor/autoload.php';
$environment = new \League\CommonMark\Environment\Environment(['html_input'=>'strip']);
$environment->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension);
$environment->addExtension(new \League\CommonMark\Extension\Table\TableExtension);
$converter = new \League\CommonMark\MarkdownConverter($environment);
$theme = file_get_contents('C:/Users/daycr/.codex/plugins/cache/daycry/custom-agents/1.21.1/skills/to-pdf/assets/theme.css');
$body = $converter->convert(file_get_contents(__DIR__.'/report.md'));
file_put_contents(__DIR__.'/report.html','<!doctype html><html lang="es"><meta charset="utf-8"><title>T12 datasets — transporte intento1</title><style>'.$theme.'pre {white-space:pre-wrap;overflow-wrap:anywhere;font-size:8pt} h2,h3 {break-after:avoid} table {font-size:8pt;table-layout:fixed;width:100%;break-inside:auto} td,th {overflow-wrap:anywhere} tr {break-inside:avoid} </style><body><main class="doc">'.$body.'</main></body></html>');
