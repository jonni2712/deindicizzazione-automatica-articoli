<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
add_action('admin_menu', 'icrea_deindex_admin_menu');
function icrea_deindex_admin_menu() {
    add_menu_page('Deindex Articoli', 'Deindex Articoli', 'manage_options', 'icrea-deindex-articoli', 'icrea_deindex_settings_page');
}

function icrea_deindex_settings_page() {
    if (
        isset($_POST['icrea_deindex_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['icrea_deindex_nonce'])), 'icrea_deindex_save')
    ) {
        // Gestione delle categorie multiple
        $categories = isset($_POST['icrea_deindex_categories']) ? array_map('sanitize_text_field', wp_unslash($_POST['icrea_deindex_categories'])) : array();
        update_option('icrea_deindex_categories', $categories);

        // Retrocompatibilità con la vecchia opzione
        update_option('icrea_deindex_category', !empty($categories) ? $categories[0] : '');

        // Giorni per noindex
        update_option('icrea_deindex_days', intval(wp_unslash($_POST['icrea_deindex_days'] ?? '7')));

        // Giorni per cambio stato (archiviazione)
        update_option('icrea_deindex_archive_days', intval(wp_unslash($_POST['icrea_deindex_archive_days'] ?? '0')));
        update_option('icrea_deindex_archive_status', sanitize_text_field(wp_unslash($_POST['icrea_deindex_archive_status'] ?? 'draft')));

        // Email personalizzate
        update_option('icrea_deindex_emails', sanitize_text_field(wp_unslash($_POST['icrea_deindex_emails'] ?? '')));
        update_option('icrea_deindex_email_subject', sanitize_text_field(wp_unslash($_POST['icrea_deindex_email_subject'] ?? '')));
        update_option('icrea_deindex_email_template', wp_kses_post(wp_unslash($_POST['icrea_deindex_email_template'] ?? '')));

        // Opzioni aggiuntive
        update_option('icrea_deindex_regenerate_sitemap', isset($_POST['icrea_deindex_regenerate_sitemap']) ? 1 : 0);
        update_option('icrea_deindex_dry_run', isset($_POST['icrea_deindex_dry_run']) ? 1 : 0);
        update_option('icrea_deindex_authors', isset($_POST['icrea_deindex_authors']) ? array_map('intval', wp_unslash($_POST['icrea_deindex_authors'])) : array());
        update_option('icrea_deindex_authors_mode', sanitize_text_field(wp_unslash($_POST['icrea_deindex_authors_mode'] ?? 'exclude')));
        update_option('icrea_deindex_cron_frequency', sanitize_text_field(wp_unslash($_POST['icrea_deindex_cron_frequency'] ?? 'hourly')));
        update_option('icrea_deindex_add_expired_tag', isset($_POST['icrea_deindex_add_expired_tag']) ? 1 : 0);

        // Aggiorna la frequenza del cron se è cambiata
        $old_frequency = get_option('icrea_deindex_cron_frequency_current', 'hourly');
        $new_frequency = sanitize_text_field(wp_unslash($_POST['icrea_deindex_cron_frequency'] ?? 'hourly'));

        if ($old_frequency !== $new_frequency) {
            wp_clear_scheduled_hook('icrea_deindex_cron_hook');
            wp_schedule_event(time(), $new_frequency, 'icrea_deindex_cron_hook');
            update_option('icrea_deindex_cron_frequency_current', $new_frequency);
        }

        echo '<div class="updated"><p>Impostazioni salvate.</p></div>';
    }

    // Recupera le opzioni
    $categories = get_option('icrea_deindex_categories', array());
    $days = get_option('icrea_deindex_days', 7);
    $archive_days = get_option('icrea_deindex_archive_days', 0);
    $archive_status = get_option('icrea_deindex_archive_status', 'draft');
    $emails = get_option('icrea_deindex_emails', '');
    $email_subject = get_option('icrea_deindex_email_subject', 'Articoli deindicizzati automaticamente');
    $email_template = get_option('icrea_deindex_email_template', "I seguenti articoli sono stati deindicizzati:\n\n{post_titles}");
    $regenerate_sitemap = get_option('icrea_deindex_regenerate_sitemap', 0);
    $dry_run = get_option('icrea_deindex_dry_run', 0);
    $authors = get_option('icrea_deindex_authors', array());
    $authors_mode = get_option('icrea_deindex_authors_mode', 'exclude');
    $cron_frequency = get_option('icrea_deindex_cron_frequency', 'hourly');
    $add_expired_tag = get_option('icrea_deindex_add_expired_tag', 0);

    echo '<div class="wrap"><h1>Deindicizzazione Articoli</h1>';

    // Tabs di navigazione
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="?page=icrea-deindex-articoli&tab=settings" class="nav-tab ' . ($current_tab == 'settings' ? 'nav-tab-active' : '') . '">Impostazioni</a>';
    echo '<a href="?page=icrea-deindex-articoli&tab=logs" class="nav-tab ' . ($current_tab == 'logs' ? 'nav-tab-active' : '') . '">Log</a>';
    echo '</h2>';

    if ($current_tab == 'logs') {
        // Visualizzazione dei log
        echo '<div class="icrea-log-container">';

        // Gestione delle azioni sui log
        if (isset($_POST['icrea_deindex_log_action'])) {
            $log_action = sanitize_text_field($_POST['icrea_deindex_log_action']);
            $upload_dir = wp_upload_dir();
            $log_dir = trailingslashit($upload_dir['basedir']) . 'deindicizzazione-automatica-articoli';
            $log_file = trailingslashit($log_dir) . 'log.txt';

            // Validate log file path is within uploads directory
            if (strpos($log_file, $upload_dir['basedir']) !== 0) {
                wp_die('Invalid log file path');
            }

            if ($log_action === 'clear' && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['icrea_log_nonce'])), 'icrea_log_action')) {
                if (file_exists($log_file)) {
                    file_put_contents($log_file, '');
                    echo '<div class="updated"><p>Log svuotato con successo.</p></div>';
                }
            } elseif ($log_action === 'download' && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['icrea_log_nonce'])), 'icrea_log_action')) {
                // Il download viene gestito tramite JavaScript
            }
        }

        // Conteggio articoli processati
        $processed_count = get_option('icrea_deindex_processed_count', 0);
        echo '<div class="icrea-stats-box">';
        echo '<h3>Statistiche</h3>';
        echo '<p>Articoli processati totali: <strong>' . esc_html($processed_count) . '</strong></p>';
        echo '</div>';

        // Visualizzazione del contenuto del log
        $upload_dir = wp_upload_dir();
        $log_dir = trailingslashit($upload_dir['basedir']) . 'deindicizzazione-automatica-articoli';
        $log_file = trailingslashit($log_dir) . 'log.txt';
        $log_content = '';

        // Validate log file path is within uploads directory
        if (strpos($log_file, $upload_dir['basedir']) !== 0) {
            wp_die('Invalid log file path');
        }

        if (file_exists($log_file)) {
            $log_content = file_get_contents($log_file);
        }

        echo '<h3>Log delle operazioni</h3>';
        echo '<form method="post" class="wp-clearfix" style="margin-bottom: 15px;">';
        wp_nonce_field('icrea_log_action', 'icrea_log_nonce');
        echo '<div class="icrea-log-actions">';
        echo '<button type="submit" name="icrea_deindex_log_action" value="clear" class="button">Svuota log</button>';
        echo '<button type="button" id="icrea-download-log" class="button" style="margin-left: 10px;">Scarica log</button>';
        echo '</div>';
        echo '</form>';

        echo '<div class="icrea-log-content">';
        if (!empty($log_content)) {
            echo '<pre>' . esc_html($log_content) . '</pre>';
        } else {
            echo '<p>Nessun log disponibile.</p>';
        }
        echo '</div>';

        // Script per il download del log
        echo '<script>
            document.getElementById("icrea-download-log").addEventListener("click", function() {
                var logContent = ' . json_encode($log_content) . ';
                var blob = new Blob([logContent], {type: "text/plain"});
                var url = URL.createObjectURL(blob);
                var a = document.createElement("a");
                a.href = url;
                a.download = "deindicizzazione-log.txt";
                document.body.appendChild(a);
                a.click();
                setTimeout(function() {
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                }, 0);
            });
        </script>';

        echo '</div>'; // Fine container log
    } else {
        // Form delle impostazioni
        echo '<form method="post">';
        wp_nonce_field('icrea_deindex_save', 'icrea_deindex_nonce');

        echo '<div class="icrea-settings-container">';

        // Sezione 1: Impostazioni di base
        echo '<div class="icrea-settings-section">';
        echo '<h3>Impostazioni di base</h3>';

        // Selezione categorie multiple
        echo '<div class="icrea-field-group">';
        echo '<fieldset>';
        echo '<legend class="screen-reader-text"><span>Categorie da deindicizzare</span></legend>';
        echo '<p><strong>Categorie da deindicizzare:</strong></p>';

        // Ottieni tutte le categorie
        $all_categories = get_categories(array('hide_empty' => false));

        if (!empty($all_categories)) {
            echo '<div class="icrea-categories-select">';
            foreach ($all_categories as $category) {
                $checked = in_array($category->slug, $categories) ? 'checked' : '';
                echo '<label style="display: block; margin-bottom: 4px;"><input type="checkbox" name="icrea_deindex_categories[]" value="' . esc_attr($category->slug) . '" ' . $checked . '> ' . esc_html($category->name) . '</label>';
            }
            echo '</div>';
        } else {
            echo '<p>Nessuna categoria trovata.</p>';
        }

        echo '</fieldset>';
        echo '</div>';

        // Giorni per noindex
        echo '<div class="icrea-field-group">';
        echo '<label for="icrea_deindex_days"><strong>Giorni prima di noindex:</strong></label>';
        echo '<input type="number" id="icrea_deindex_days" name="icrea_deindex_days" value="' . esc_attr($days) . '" min="1" class="small-text">';
        echo '<p class="description">Numero di giorni dopo i quali gli articoli verranno impostati come noindex, nofollow.</p>';
        echo '</div>';

        // Modalità archiviazione
        echo '<div class="icrea-field-group">';
        echo '<label for="icrea_deindex_archive_days"><strong>Giorni prima di archiviazione:</strong></label>';
        echo '<input type="number" id="icrea_deindex_archive_days" name="icrea_deindex_archive_days" value="' . esc_attr($archive_days) . '" min="0" class="small-text">';
        echo '<p class="description">Numero di giorni dopo i quali cambiare lo stato degli articoli (0 = disabilitato).</p>';
        echo '</div>';

        echo '<div class="icrea-field-group">';
        echo '<label for="icrea_deindex_archive_status"><strong>Stato di archiviazione:</strong></label>';
        echo '<select id="icrea_deindex_archive_status" name="icrea_deindex_archive_status">';
        echo '<option value="draft" ' . selected($archive_status, 'draft', false) . '>Bozza</option>';
        echo '<option value="private" ' . selected($archive_status, 'private', false) . '>Privato</option>';
        echo '</select>';
        echo '</div>';

        // Frequenza cron
        echo '<div class="icrea-field-group">';
        echo '<fieldset>';
        echo '<legend class="screen-reader-text"><span>Frequenza di esecuzione</span></legend>';
        echo '<p><strong>Frequenza di esecuzione:</strong></p>';
        echo '<p>';
        echo '<label class="radio-inline" style="margin-right: 15px;"><input type="radio" name="icrea_deindex_cron_frequency" value="hourly" ' . checked($cron_frequency, 'hourly', false) . '> Ogni ora</label>';
        echo '<label class="radio-inline" style="margin-right: 15px;"><input type="radio" name="icrea_deindex_cron_frequency" value="daily" ' . checked($cron_frequency, 'daily', false) . '> Ogni giorno</label>';
        echo '<label class="radio-inline"><input type="radio" name="icrea_deindex_cron_frequency" value="weekly" ' . checked($cron_frequency, 'weekly', false) . '> Ogni settimana</label>';
        echo '</p>';
        echo '</fieldset>';
        echo '</div>';

        echo '</div>'; // Fine sezione 1

        // Sezione 2: Notifiche email
        echo '<div class="icrea-settings-section">';
        echo '<h3>Notifiche Email</h3>';

        // Email di notifica
        echo '<div class="icrea-field-group">';
        echo '<label for="icrea_deindex_emails"><strong>Email di notifica:</strong></label>';
        echo '<input type="text" id="icrea_deindex_emails" name="icrea_deindex_emails" value="' . esc_attr($emails) . '" class="regular-text">';
        echo '<p class="description">Inserisci gli indirizzi email separati da virgola.</p>';
        echo '</div>';

        // Oggetto email
        echo '<div class="icrea-field-group">';
        echo '<label for="icrea_deindex_email_subject"><strong>Oggetto email:</strong></label>';
        echo '<input type="text" id="icrea_deindex_email_subject" name="icrea_deindex_email_subject" value="' . esc_attr($email_subject) . '" class="regular-text">';
        echo '</div>';

        // Template email
        echo '<div class="icrea-field-group">';
        echo '<label for="icrea_deindex_email_template"><strong>Template email:</strong></label>';
        echo '<textarea id="icrea_deindex_email_template" name="icrea_deindex_email_template" rows="5" class="large-text code">' . esc_textarea($email_template) . '</textarea>';
        echo '<p class="description">Puoi utilizzare i seguenti placeholder: <code>{post_titles}</code>, <code>{category}</code>, <code>{days}</code></p>';
        echo '</div>';

        echo '</div>'; // Fine sezione 2

        // Sezione 3: Opzioni avanzate
        echo '<div class="icrea-settings-section">';
        echo '<h3>Opzioni avanzate</h3>';

        // Filtro autori
        echo '<div class="icrea-field-group">';
        echo '<label for="icrea_deindex_authors_mode"><strong>Filtro autori:</strong></label>';
        echo '<select id="icrea_deindex_authors_mode" name="icrea_deindex_authors_mode" class="regular-text">';
        echo '<option value="exclude" ' . selected($authors_mode, 'exclude', false) . '>Escludi questi autori</option>';
        echo '<option value="include" ' . selected($authors_mode, 'include', false) . '>Includi solo questi autori</option>';
        echo '</select>';
        echo '</div>';

        // Lista autori
        echo '<div class="icrea-field-group">';
        echo '<fieldset>';
        echo '<legend class="screen-reader-text"><span>Seleziona autori</span></legend>';
        echo '<p><strong>Seleziona autori:</strong></p>';

        $all_authors = get_users(array('role__in' => array('administrator', 'editor', 'author', 'contributor')));

        if (!empty($all_authors)) {
            echo '<div class="icrea-authors-select">';
            foreach ($all_authors as $author) {
                $checked = in_array($author->ID, $authors) ? 'checked' : '';
                echo '<label style="display: block; margin-bottom: 4px;"><input type="checkbox" name="icrea_deindex_authors[]" value="' . esc_attr($author->ID) . '" ' . $checked . '> ' . esc_html($author->display_name) . '</label>';
            }
            echo '</div>';
        } else {
            echo '<p>Nessun autore trovato.</p>';
        }

        echo '</fieldset>';
        echo '</div>';

        // Opzioni aggiuntive
        echo '<div class="icrea-field-group">';
        echo '<fieldset>';
        echo '<legend class="screen-reader-text"><span>Opzioni aggiuntive</span></legend>';
        echo '<p><strong>Opzioni aggiuntive:</strong></p>';
        echo '<p>';
        echo '<label style="display: block; margin-bottom: 8px;"><input type="checkbox" name="icrea_deindex_regenerate_sitemap" value="1" ' . checked($regenerate_sitemap, 1, false) . '> Rigenera sitemap automaticamente</label>';
        echo '<label style="display: block; margin-bottom: 8px;"><input type="checkbox" name="icrea_deindex_dry_run" value="1" ' . checked($dry_run, 1, false) . '> Modalità simulazione (non modifica articoli)</label>';
        echo '<label style="display: block;"><input type="checkbox" name="icrea_deindex_add_expired_tag" value="1" ' . checked($add_expired_tag, 1, false) . '> Aggiungi tag "scaduto" agli articoli vecchi</label>';
        echo '</p>';
        echo '</fieldset>';
        echo '</div>';

        echo '</div>'; // Fine sezione 3

        echo '</div>'; // Fine container impostazioni

        // Pulsante salva
        echo '<p><input type="submit" value="Salva impostazioni" class="button button-primary"></p>';
        echo '</form>';

        // Stili CSS inline
        echo '<style>
            .icrea-settings-container {
                margin-top: 15px;
            }
            .icrea-settings-section {
                background: #fff;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                margin-bottom: 15px;
                padding: 12px;
            }
            .icrea-field-group {
                margin-bottom: 12px;
                padding-bottom: 12px;
            }
            .icrea-field-group:last-child {
                padding-bottom: 0;
                margin-bottom: 0;
            }
            .icrea-field-group label {
                display: block;
                margin-bottom: 5px;
            }
            .icrea-field-group input[type="text"],
            .icrea-field-group input[type="number"],
            .icrea-field-group select,
            .icrea-field-group textarea {
                margin: 2px 0 5px;
            }
            .icrea-field-group .description {
                margin: 2px 0 5px;
                color: #666;
            }
            .icrea-categories-select, .icrea-authors-select {
                max-height: 120px;
                overflow-y: auto;
                margin: 5px 0;
                padding: 5px;
                border: 1px solid #ddd;
            }
            .icrea-categories-select label, .icrea-authors-select label {
                margin: 3px 0;
                display: block;
            }
            .icrea-log-container {
                margin-top: 15px;
            }
            .icrea-log-actions {
                margin-bottom: 10px;
            }
            .icrea-log-content {
                border: 1px solid #ddd;
                padding: 10px;
                max-height: 400px;
                overflow-y: auto;
            }
            .icrea-log-content pre {
                margin: 0;
                white-space: pre-wrap;
            }
            .icrea-stats-box {
                background: #fff;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                margin-bottom: 15px;
                padding: 12px;
            }
        </style>';
    }
}
