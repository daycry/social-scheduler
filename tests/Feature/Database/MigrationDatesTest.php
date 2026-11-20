<?php

test('migration filenames use a valid UTC date no later than today', function () {
    // Filenames already present when the check was introduced (2026-11-16).
    $legacy = [
        '2026_11_17_090000_create_inbox_comment_notes_table.php',
        '2026_11_17_090100_create_calendar_views_table.php',
        '2026_11_18_090000_add_credential_secret_to_social_accounts.php',
        '2026_11_19_090000_add_media_to_post_targets.php',
        '2026_11_20_090000_add_approval_and_series_to_posts_table.php',
        '2026_11_20_090100_add_manual_to_post_targets.php',
        '2026_11_20_090200_create_brand_collaborators_table.php',
        '2026_11_20_090300_create_post_series_tables.php',
        '2026_11_20_090400_create_idempotency_keys_table.php',
        '2026_11_20_090500_add_approval_signature_to_posts_table.php',
        '2026_11_25_090000_extend_inbox_triage.php',
        '2026_11_25_090100_create_competitor_posts_table.php',
        '2026_11_25_090200_extend_smart_link_blocks.php',
        '2026_12_02_090000_create_report_builder_tables.php',
        '2026_12_03_090000_create_sites_tables.php',
        '2026_12_03_090100_site_public_slug_messages_and_history.php',
        '2026_12_05_090000_create_studio_ai_jobs.php',
        '2026_12_08_090000_create_canva_integration.php',
        '2026_12_10_090000_add_last_error_to_post_series.php',
        '2026_12_15_090000_add_retention_indexes.php',
        '2026_12_16_090000_add_revision_autosave_flag_and_notification_index.php',
        '2026_12_17_090000_add_superseded_at_to_design_revisions.php',
        '2026_12_18_090000_create_post_target_media_tables.php',
        '2026_12_19_090000_add_notify_by_mail_to_users.php',
        '2026_12_20_090000_add_network_to_competitors.php',
        '2026_12_21_090000_drop_download_urls_from_canva_jobs.php',
        '2026_12_22_090000_add_manual_reminded_at_to_post_targets.php',
        '2026_12_23_090000_add_rule_changed_at_to_post_series.php',
        '2026_12_23_090100_add_manual_status_index_to_post_targets.php',
        '2026_12_24_090000_add_thumbnail_to_studio_designs.php',
    ];

    $today = now('UTC')->format('Y_m_d');
    $invalid = [];
    $migrations = glob(database_path('migrations/*.php'));
    expect($migrations)->not->toBeEmpty();

    foreach ($migrations as $path) {
        $migration = basename($path);
        if (! preg_match('/^(\d{4})_(\d{2})_(\d{2})_(?:[01]\d|2[0-3])[0-5]\d[0-5]\d_.+\.php$/D', $migration, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (substr($migration, 0, 10) > $today && ! in_array($migration, $legacy, true))) {
            $invalid[] = $migration;
        }
    }

    $this->assertSame([], $invalid, "Migration filenames must use a valid timestamp with a date up to {$today} (UTC).");
});
