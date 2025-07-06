<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Aggiungi widget alla dashboard
add_action('wp_dashboard_setup', 'icrea_deindex_add_dashboard_widget');

function icrea_deindex_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'icrea_deindex_dashboard_widget',
        'Deindicizzazione Articoli - Stato',
        'icrea_deindex_dashboard_widget_content'
    );
}

function icrea_deindex_dashboard_widget_content() {
    // Recupera le statistiche
    $processed_count = get_option('icrea_deindex_processed_count', 0);
    $categories = get_option('icrea_deindex_categories', array());
    $days = intval(get_option('icrea_deindex_days', 7));
    $archive_days = intval(get_option('icrea_deindex_archive_days', 0));

    // Calcola la data dell'ultima esecuzione e della prossima
    $next_run = wp_next_scheduled('icrea_deindex_cron_hook');
    $cron_frequency = get_option('icrea_deindex_cron_frequency', 'hourly');

    echo '<div class="icrea-dashboard-widget">';

    // Mostra statistiche
    echo '<div class="icrea-dashboard-stats">';
    echo '<h4>Statistiche</h4>';
    echo '<p>Articoli processati nell\'ultimo mese: <strong>' . esc_html($processed_count) . '</strong></p>';

    // Ottieni gli ultimi articoli deindicizzati
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . 'deindicizzazione-automatica-articoli';
    $log_file = trailingslashit($log_dir) . 'log.txt';
    $recent_activity = '';

    // Validate log file path is within uploads directory
    if (strpos($log_file, $upload_dir['basedir']) !== 0) {
        return; // Exit silently for dashboard widget
    }

    if (file_exists($log_file)) {
        $logs = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logs = array_slice($logs, -5); // Prendi le ultime 5 righe
        if (!empty($logs)) {
            $recent_activity = '<h4>Attività recenti</h4><ul>';
            foreach ($logs as $log) {
                $recent_activity .= '<li>' . esc_html($log) . '</li>';
            }
            $recent_activity .= '</ul>';
        }
    }

    if (!empty($recent_activity)) {
        echo $recent_activity;
    } else {
        echo '<p>Nessuna attività recente registrata.</p>';
    }

    echo '</div>';

    // Mostra prossime azioni
    echo '<div class="icrea-dashboard-upcoming">';
    echo '<h4>Prossime azioni pianificate</h4>';

    if ($next_run) {
        $time_diff = $next_run - time();
        $hours = floor($time_diff / 3600);
        $minutes = floor(($time_diff % 3600) / 60);

        echo '<p>Prossima esecuzione: <strong>' . date_i18n('d/m/Y H:i', $next_run) . '</strong> ';
        echo '(tra ' . $hours . ' ore e ' . $minutes . ' minuti)</p>';

        // Mostra cosa verrà fatto
        echo '<p>Azioni previste:</p><ul>';

        if ($days > 0 && !empty($categories)) {
            echo '<li>Deindicizzazione articoli nelle categorie <strong>' . esc_html(implode(', ', $categories)) . '</strong> più vecchi di <strong>' . esc_html($days) . ' giorni</strong></li>';
        }

        if ($archive_days > 0 && !empty($categories)) {
            $archive_status = get_option('icrea_deindex_archive_status', 'draft');
            $status_label = $archive_status === 'draft' ? 'bozza' : 'privato';
            echo '<li>Archiviazione (stato: <strong>' . esc_html($status_label) . '</strong>) articoli più vecchi di <strong>' . esc_html($archive_days) . ' giorni</strong></li>';
        }

        echo '</ul>';

        // Mostra frequenza
        $frequency_label = '';
        switch ($cron_frequency) {
            case 'hourly':
                $frequency_label = 'ogni ora';
                break;
            case 'daily':
                $frequency_label = 'ogni giorno';
                break;
            case 'weekly':
                $frequency_label = 'ogni settimana';
                break;
        }

        echo '<p>Frequenza di esecuzione: <strong>' . esc_html($frequency_label) . '</strong></p>';
    } else {
        echo '<p>Nessuna azione pianificata. Verifica le impostazioni del plugin.</p>';
    }

    echo '</div>';

    // Link alle impostazioni
    echo '<p class="icrea-dashboard-link"><a href="' . esc_url(admin_url('admin.php?page=icrea-deindex-articoli')) . '">Gestisci impostazioni</a></p>';

    // Stili CSS inline
    echo '<style>
        .icrea-dashboard-widget {
            margin-bottom: 8px;
        }
        .icrea-dashboard-stats, .icrea-dashboard-upcoming {
            margin-bottom: 12px;
        }
        .icrea-dashboard-widget h4 {
            margin: 0 0 8px 0;
            padding-bottom: 4px;
            border-bottom: 1px solid #eee;
        }
        .icrea-dashboard-widget p {
            margin: 0 0 8px 0;
        }
        .icrea-dashboard-widget ul {
            margin: 0 0 12px 15px;
            list-style-type: disc;
        }
        .icrea-dashboard-widget li {
            margin-bottom: 4px;
        }
        .icrea-dashboard-link {
            margin-top: 12px;
            text-align: right;
        }
    </style>';

    echo '</div>';
}
function icrea_deindex_old_articles() {
    if (!function_exists('update_post_meta')) return;

    // Recupera le impostazioni
    $categories = get_option('icrea_deindex_categories', array());
    $days = intval(get_option('icrea_deindex_days', 7));
    $archive_days = intval(get_option('icrea_deindex_archive_days', 0));
    $archive_status = get_option('icrea_deindex_archive_status', 'draft');
    $emails = get_option('icrea_deindex_emails', '');
    $email_subject = get_option('icrea_deindex_email_subject', 'Articoli deindicizzati automaticamente');
    $email_template = get_option('icrea_deindex_email_template', "I seguenti articoli sono stati deindicizzati:\n\n{post_titles}");
    $regenerate_sitemap = get_option('icrea_deindex_regenerate_sitemap', 0);
    $dry_run = get_option('icrea_deindex_dry_run', 0);
    $authors = get_option('icrea_deindex_authors', array());
    $authors_mode = get_option('icrea_deindex_authors_mode', 'exclude');
    $add_expired_tag = get_option('icrea_deindex_add_expired_tag', 0);

    // Retrocompatibilità con la vecchia opzione
    if (empty($categories)) {
        $old_category = get_option('icrea_deindex_category', '');
        if (!empty($old_category)) {
            $categories = array($old_category);
        }
    }

    // Se non ci sono categorie configurate, esci
    if (empty($categories)) return;

    // Array per tenere traccia dei post processati
    $deindexed_posts = array();
    $archived_posts = array();
    $tagged_posts = array();
    $processed_count = 0;

    // Processa i post per noindex
    if ($days > 0) {
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'date_query' => array(
                array(
                    'column' => 'post_date',
                    'before' => "{$days} days ago",
                ),
            ),
        );

        // Aggiungi filtro per autori se configurato
        if (!empty($authors)) {
            if ($authors_mode === 'include') {
                $args['author__in'] = $authors;
            } else {
                $args['author__not_in'] = $authors;
            }
        }

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $post_categories = get_the_category();
                $should_process = false;

                // Verifica se il post appartiene a una delle categorie configurate
                foreach ($post_categories as $post_category) {
                    if (in_array($post_category->slug, $categories)) {
                        $should_process = true;
                        break;
                    }
                }

                if (!$should_process) continue;

                // Incrementa il contatore dei post processati
                $processed_count++;

                // Se non è in modalità simulazione, applica noindex
                if (!$dry_run) {
                    delete_post_meta($post_id, 'rank_math_robots');
                    update_post_meta($post_id, 'rank_math_robots', serialize(['noindex', 'nofollow']));

                    // Aggiungi tag "scaduto" se l'opzione è attiva
                    if ($add_expired_tag) {
                        $expired_tag = term_exists('scaduto', 'post_tag');
                        if (!$expired_tag) {
                            $expired_tag = wp_insert_term('scaduto', 'post_tag');
                        }

                        if (!is_wp_error($expired_tag)) {
                            wp_set_post_tags($post_id, 'scaduto', true);
                            $tagged_posts[] = get_the_title();
                        }
                    }
                }

                $deindexed_posts[] = get_the_title();
            }
            wp_reset_postdata();
        }
    }

    // Processa i post per archiviazione
    if ($archive_days > 0) {
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'date_query' => array(
                array(
                    'column' => 'post_date',
                    'before' => "{$archive_days} days ago",
                ),
            ),
        );

        // Aggiungi filtro per autori se configurato
        if (!empty($authors)) {
            if ($authors_mode === 'include') {
                $args['author__in'] = $authors;
            } else {
                $args['author__not_in'] = $authors;
            }
        }

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $post_categories = get_the_category();
                $should_process = false;

                // Verifica se il post appartiene a una delle categorie configurate
                foreach ($post_categories as $post_category) {
                    if (in_array($post_category->slug, $categories)) {
                        $should_process = true;
                        break;
                    }
                }

                if (!$should_process) continue;

                // Incrementa il contatore dei post processati
                $processed_count++;

                // Se non è in modalità simulazione, cambia lo stato del post
                if (!$dry_run) {
                    wp_update_post(array(
                        'ID' => $post_id,
                        'post_status' => $archive_status
                    ));
                }

                $archived_posts[] = get_the_title();
            }
            wp_reset_postdata();
        }
    }

    // Aggiorna il contatore totale dei post processati
    $total_processed = get_option('icrea_deindex_processed_count', 0) + $processed_count;
    update_option('icrea_deindex_processed_count', $total_processed);

    // Invia email di notifica se ci sono post processati e indirizzi email configurati
    if ($emails && ($deindexed_posts || $archived_posts)) {
        // Prepara il messaggio email con i placeholder
        $message = $email_template;

        // Sanitize post titles
        $sanitized_deindexed_posts = array_map('sanitize_text_field', $deindexed_posts);
        $sanitized_archived_posts = array_map('sanitize_text_field', $archived_posts);
        $all_posts = array_merge($sanitized_deindexed_posts, $sanitized_archived_posts);

        // Sanitize categories
        $sanitized_categories = array_map('sanitize_text_field', $categories);

        $message = str_replace('{post_titles}', implode("\n", $all_posts), $message);
        $message = str_replace('{category}', implode(', ', $sanitized_categories), $message);
        $message = str_replace('{days}', intval($days), $message);

        // Aggiungi informazioni sui post archiviati se ce ne sono
        if (!empty($sanitized_archived_posts)) {
            $safe_archive_status = sanitize_text_field($archive_status);
            $message .= "\n\nArticoli archiviati (stato: {$safe_archive_status}):\n" . implode("\n", $sanitized_archived_posts);
        }

        // Aggiungi informazioni sui post taggati se ce ne sono
        if (!empty($tagged_posts)) {
            $sanitized_tagged_posts = array_map('sanitize_text_field', $tagged_posts);
            $message .= "\n\nArticoli taggati come 'scaduto':\n" . implode("\n", $sanitized_tagged_posts);
        }

        // Aggiungi nota se in modalità simulazione
        if ($dry_run) {
            $message = "[SIMULAZIONE] Nessuna modifica è stata apportata.\n\n" . $message;
        }

        // Sanitize email addresses
        $email_list = explode(',', $emails);
        $sanitized_emails = array();
        foreach ($email_list as $email) {
            $sanitized_email = sanitize_email(trim($email));
            if (!empty($sanitized_email)) {
                $sanitized_emails[] = $sanitized_email;
            }
        }

        if (!empty($sanitized_emails)) {
            wp_mail($sanitized_emails, $email_subject, $message);
        }
    }

    // Scrivi nel log
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . 'deindicizzazione-automatica-articoli';
    $log_file = trailingslashit($log_dir) . 'log.txt';

    // Validate log file path is within uploads directory
    if (strpos($log_file, $upload_dir['basedir']) !== 0) {
        return; // Exit silently if path is invalid
    }

    wp_mkdir_p($log_dir);

    $log_message = "[" . current_time("Y-m-d H:i:s") . "] ";

    if ($dry_run) {
        $log_message .= "[SIMULAZIONE] ";
    }

    // Sanitize categories for log
    $sanitized_categories_log = array_map('sanitize_text_field', $categories);
    $categories_string = implode(', ', $sanitized_categories_log);

    $log_message .= "Processati " . intval($processed_count) . " post nelle categorie '" . $categories_string . "'. ";
    $log_message .= "Deindicizzati: " . count($deindexed_posts) . " dopo " . intval($days) . " giorni. ";

    if ($archive_days > 0) {
        $log_message .= "Archiviati: " . count($archived_posts) . " dopo " . intval($archive_days) . " giorni. ";
    }

    if ($add_expired_tag) {
        $log_message .= "Taggati come 'scaduto': " . count($tagged_posts) . ". ";
    }

    $log_message .= "\n";

    file_put_contents($log_file, $log_message, FILE_APPEND);

    // Rigenera sitemap se l'opzione è attiva
    if ($regenerate_sitemap && !$dry_run) {
        // Supporto per Rank Math
        if (function_exists('rank_math') && method_exists(rank_math()->xml_sitemap, 'build_sitemap')) {
            rank_math()->xml_sitemap->build_sitemap();
        }

        // Supporto per Yoast SEO
        if (function_exists('wpseo_rebuild_sitemap')) {
            wpseo_rebuild_sitemap();
        } elseif (has_action('wpseo_build_sitemap_index')) {
            do_action('wpseo_build_sitemap_index');
        }
    }
}
