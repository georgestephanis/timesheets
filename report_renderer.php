<?php
// report_renderer.php
// Serve reports via PHP's built-in web server

// Parse query parameters first
$format = $_GET['format'] ?? 'html';

// Set up basic headers based on $format
if ($format === 'txt' || $format === 'text') {
    header('Content-Type: text/plain; charset=UTF-8');
} else {
    header('Content-Type: text/html; charset=UTF-8');
}
header('Cache-Control: no-cache');

// Include necessary project files
require_once __DIR__ . '/activity-report.php';

// Load configuration
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);

// Parse query parameters
$dateRange = $_GET['date_range'] ?? '7days';
$project = $_GET['project'] ?? null;

// Generate report data
require_once __DIR__ . '/src/cli.php';
$reportData = main($config, $dateRange, $project, 'html', false, false, null, null);

// Render HTML output
function renderReport($reportData, $format = 'html', $dateRange = '7days', $project = null) {
    // Include renderer functions
    require_once __DIR__ . '/src/renderers.php';

    // Render based on format
    if ($format === 'html') {
        return renderMarkdown($reportData);
    } elseif ($format === 'json') {
        return json_encode($reportData, JSON_PRETTY_PRINT);
    } elseif ($format === 'tsv') {
        return renderTsv($reportData);
    }

    return "Unsupported format: $format";
}

// Output the rendered report
echo renderReport($reportData, $format, '7days', null);
?>