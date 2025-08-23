#!/usr/bin/env php
<?php
// Simple skeleton dispatcher for campaigns. Wire into framework/cron as needed.
// Usage: php scripts/cron/campaign_dispatcher.php --run

$opts = getopt('', ['run']);
if (!isset($opts['run'])) {
    echo "Usage: php campaign_dispatcher.php --run\n";
    exit(0);
}

// TODO: bootstrap CodeIgniter or load framework to use Campaign_model and Message senders
echo "Campaign dispatcher skeleton executed. Implement runner logic to select campaigns and enqueue messages.\n";
