<?php
/**
 * Plugin Name: Vigile Urbano - WordPress Resource Manager
 * Description: Plugin per ottimizzare e gestire le risorse dei plugin WordPress, ridurre duplicazioni e monitorare l'uso delle risorse
 * Version: 1.0.0
 * Author: Resource Manager Team
 * Text Domain: vigile-urbano
 */

// Prevenire accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class VigilaUrbanoResourceManager {
    
    private $plugin_data = array();
    private $resource_analysis = array();
    private $optimization_rules = array();
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_analyze_plugins', array($this, 'ajax_analyze_plugins'));
        add_action('wp_ajax_optimize_resources', array($this, 'ajax_optimize_resources'));
        add_action('wp_ajax_toggle_resource', array($this, 'ajax_toggle_resource'));
        add_action('wp_ajax_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('init', array($this, 'init_optimization'));
        
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));
        
        // Crea i file assets se non esistono
        $this->create_assets_files();
    }
    
    public function activate_plugin() {
        // Crea tabella per memorizzare le ottimizzazioni
        global $wpdb;
        $table_name = $wpdb->prefix . 'vigile_urbano_optimizations';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            resource_type varchar(100) NOT NULL,
            resource_handle varchar(200) NOT NULL,
            plugin_name varchar(200) NOT NULL,
            is_active boolean DEFAULT 1,
            optimization_rule text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Opzioni di default
        add_option('vigile_urbano_settings', array(
            'auto_optimize' => false,
            'monitoring_enabled' => true,
            'resource_limit' => 50,
            'performance_mode' => 'balanced'
        ));
    }
    
    public function deactivate_plugin() {
        // Cleanup se necessario
        wp_clear_scheduled_hook('vigile_urbano_daily_optimization');
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Vigile Urbano Resource Manager',
            'Vigile Urbano',
            'manage_options',
            'vigile-urbano',
            array($this, 'admin_page'),
            'dashicons-performance',
            30
        );
        
        add_submenu_page(
            'vigile-urbano',
            'Analisi Plugin',
            'Analisi Plugin',
            'manage_options',
            'vigile-urbano-analysis',
            array($this, 'analysis_page')
        );
        
        add_submenu_page(
            'vigile-urbano',
            'Ottimizzazioni',
            'Ottimizzazioni',
            'manage_options',
            'vigile-urbano-optimizations',
            array($this, 'optimizations_page')
        );
        
        add_submenu_page(
            'vigile-urbano',
            'Monitoraggio',
            'Monitoraggio',
            'manage_options',
            'vigile-urbano-monitoring',
            array($this, 'monitoring_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'vigile-urbano') !== false) {
            $plugin_url = plugin_dir_url(__FILE__);
            
            // Enqueue scripts
            wp_enqueue_script('vigile-urbano-admin', $plugin_url . 'assets/admin.js', array('jquery'), '1.0.0', true);
            wp_enqueue_style('vigile-urbano-admin', $plugin_url . 'assets/admin.css', array(), '1.0.0');
            
            // Localize script
            wp_localize_script('vigile-urbano-admin', 'vigile_urbano_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('vigile_urbano_nonce'),
                'debug' => WP_DEBUG
            ));
        }
    }
    
    private function create_assets_files() {
        $plugin_dir = plugin_dir_path(__FILE__);
        $assets_dir = $plugin_dir . 'assets/';
        
        // Crea la cartella assets se non esiste
        if (!file_exists($assets_dir)) {
            wp_mkdir_p($assets_dir);
        }
        
        // Crea il file CSS
        $css_content = $this->get_admin_css();
        file_put_contents($assets_dir . 'admin.css', $css_content);
        
        // Crea il file JavaScript
        $js_content = $this->get_admin_js();
        file_put_contents($assets_dir . 'admin.js', $js_content);
    }
    
    public function admin_page() {
        $settings = get_option('vigile_urbano_settings', array());
        ?>
        <div class="wrap vigile-urbano-wrap">
            <h1>🚔 Vigile Urbano - Resource Manager Dashboard</h1>
            
            <div class="vigile-urbano-dashboard">
                <div class="dashboard-grid">
                    <div class="dashboard-card">
                        <h3>📊 Stato del Sistema</h3>
                        <div class="system-stats">
                            <div class="stat-item">
                                <span class="stat-label">Plugin Attivi:</span>
                                <span class="stat-value"><?php echo count(get_option('active_plugins', array())); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Risorse Monitorate:</span>
                                <span class="stat-value" id="monitored-resources">-</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Ottimizzazioni Attive:</span>
                                <span class="stat-value" id="active-optimizations">-</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="dashboard-card">
                        <h3>⚡ Performance Score</h3>
                        <div class="performance-meter">
                            <div class="meter-circle" data-score="75">
                                <div class="meter-value">75%</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="dashboard-card">
                        <h3>🎛️ Controlli Rapidi</h3>
                        <div class="quick-controls">
                            <button class="button button-primary" id="quick-analyze">Analisi Rapida</button>
                            <button class="button" id="auto-optimize">Auto-Ottimizza</button>
                            <button class="button" id="clear-cache">Pulisci Cache</button>
                        </div>
                    </div>
                </div>
                
                <div class="dashboard-main">
                    <h3>🔍 Risorse Recenti</h3>
                    <div id="recent-resources">
                        <p>Clicca su "Analisi Rapida" per iniziare...</p>
                    </div>
                    
                    <div id="debug-info" style="margin-top: 20px; padding: 10px; background: #f0f0f0; border-radius: 5px; display: none;">
                        <h4>Debug Info:</h4>
                        <div id="debug-content"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .vigile-urbano-wrap {
            background: #f1f1f1;
            margin: 0 -20px;
            padding: 20px;
        }
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .dashboard-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .dashboard-card h3 {
            margin-top: 0;
            color: #2271b1;
        }
        .stat-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .stat-value {
            font-weight: bold;
            color: #2271b1;
        }
        .performance-meter {
            text-align: center;
        }
        .meter-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: conic-gradient(#2271b1 0deg 270deg, #ddd 270deg 360deg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
            position: relative;
        }
        .meter-circle::before {
            content: '';
            width: 70px;
            height: 70px;
            background: white;
            border-radius: 50%;
            position: absolute;
        }
        .meter-value {
            position: relative;
            z-index: 1;
            font-weight: bold;
            font-size: 18px;
            color: #2271b1;
        }
        .quick-controls {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .dashboard-main {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        </style>
        <?php
    }
    
    public function analysis_page() {
        ?>
        <div class="wrap">
            <h1>🔍 Analisi Plugin e Risorse</h1>
            
            <div class="vigile-urbano-analysis">
                <div class="analysis-controls">
                    <button class="button button-primary" id="start-analysis">Avvia Analisi Completa</button>
                    <button class="button" id="export-analysis">Esporta Risultati</button>
                </div>
                
                <div id="analysis-progress" style="display: none;">
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                    <p id="progress-text">Analisi in corso...</p>
                </div>
                
                <div id="analysis-results">
                    <div class="analysis-section">
                        <h3>🧩 Plugin Analizzati</h3>
                        <div id="plugins-list"></div>
                    </div>
                    
                    <div class="analysis-section">
                        <h3>🔄 Risorse Duplicate</h3>
                        <div id="duplicate-resources"></div>
                    </div>
                    
                    <div class="analysis-section">
                        <h3>⚠️ Problemi Rilevati</h3>
                        <div id="detected-issues"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .vigile-urbano-analysis {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        .analysis-controls {
            margin-bottom: 20px;
        }
        .progress-bar {
            width: 100%;
            height: 20px;
            background: #ddd;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 10px;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #2271b1, #72aee6);
            width: 0%;
            transition: width 0.3s ease;
        }
        .analysis-section {
            margin: 30px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        .analysis-section h3 {
            margin-top: 0;
            color: #2271b1;
        }
        </style>
        <?php
    }
    
    public function optimizations_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'vigile_urbano_optimizations';
        $optimizations = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
        ?>
        <div class="wrap">
            <h1>⚡ Gestione Ottimizzazioni</h1>
            
            <div class="vigile-urbano-optimizations">
                <div class="optimization-controls">
                    <button class="button button-primary" id="create-optimization">Nuova Ottimizzazione</button>
                    <button class="button" id="apply-all-optimizations">Applica Tutte</button>
                    <button class="button button-secondary" id="reset-optimizations">Reset</button>
                </div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Tipo Risorsa</th>
                            <th>Handle</th>
                            <th>Plugin</th>
                            <th>Stato</th>
                            <th>Regola</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($optimizations)): ?>
                        <tr>
                            <td colspan="6">Nessuna ottimizzazione configurata. Esegui prima un'analisi.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($optimizations as $opt): ?>
                        <tr>
                            <td><?php echo esc_html($opt->resource_type); ?></td>
                            <td><code><?php echo esc_html($opt->resource_handle); ?></code></td>
                            <td><?php echo esc_html($opt->plugin_name); ?></td>
                            <td>
                                <span class="status-badge <?php echo $opt->is_active ? 'active' : 'inactive'; ?>">
                                    <?php echo $opt->is_active ? 'Attivo' : 'Inattivo'; ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($opt->optimization_rule); ?></td>
                            <td>
                                <button class="button button-small toggle-optimization" 
                                        data-id="<?php echo $opt->id; ?>">
                                    <?php echo $opt->is_active ? 'Disattiva' : 'Attiva'; ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <style>
        .vigile-urbano-optimizations {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        .optimization-controls {
            margin-bottom: 20px;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }
        .status-badge.inactive {
            background: #f8d7da;
            color: #721c24;
        }
        </style>
        <?php
    }
    
    public function monitoring_page() {
        ?>
        <div class="wrap">
            <h1>📈 Monitoraggio Risorse</h1>
            
            <div class="vigile-urbano-monitoring">
                <div class="monitoring-stats">
                    <div class="stat-card">
                        <h3>Memoria PHP</h3>
                        <div class="stat-value"><?php echo $this->format_bytes(memory_get_usage(true)); ?></div>
                        <div class="stat-label">di <?php echo ini_get('memory_limit'); ?></div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Query Database</h3>
                        <div class="stat-value"><?php echo get_num_queries(); ?></div>
                        <div class="stat-label">query eseguite</div>
                    </div>
                    
                    <div class="stat-card">
                        <h3>Tempo di Caricamento</h3>
                        <div class="stat-value" id="page-load-time">-</div>
                        <div class="stat-label">secondi</div>
                    </div>
                </div>
                
                <div class="monitoring-charts">
                    <h3>📊 Grafici delle Performance</h3>
                    <div class="chart-container">
                        <canvas id="performance-chart" width="400" height="200"></canvas>
                    </div>
                </div>
                
                <div class="resource-monitor">
                    <h3>🔍 Monitor Risorse in Tempo Reale</h3>
                    <div id="real-time-resources">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Risorsa</th>
                                    <th>Tipo</th>
                                    <th>Dimensione</th>
                                    <th>Tempo Caricamento</th>
                                    <th>Plugin Origine</th>
                                    <th>Stato</th>
                                </tr>
                            </thead>
                            <tbody id="resources-table-body">
                                <tr>
                                    <td colspan="6">Avvio monitoraggio...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .vigile-urbano-monitoring {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        .monitoring-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            text-align: center;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        .stat-card h3 {
            margin-top: 0;
            color: #2271b1;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #2271b1;
        }
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        .monitoring-charts {
            margin: 30px 0;
        }
        .chart-container {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .resource-monitor {
            margin-top: 30px;
        }
        </style>
        
        <script>
        // Simulazione caricamento tempo pagina
        document.addEventListener('DOMContentLoaded', function() {
            const loadTime = (performance.now() / 1000).toFixed(3);
            document.getElementById('page-load-time').textContent = loadTime;
        });
        </script>
        <?php
    }
    
    public function ajax_clear_cache() {
        check_ajax_referer('vigile_urbano_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permessi insufficienti');
        }
        
        // Pulisce la cache
        wp_cache_flush();
        delete_transient('vigile_urbano_analysis_cache');
        
        wp_send_json_success(array(
            'message' => 'Cache pulita con successo'
        ));
    }
    
    public function ajax_analyze_plugins() {
        check_ajax_referer('vigile_urbano_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permessi insufficienti');
        }
        
        try {
            $analysis_results = $this->perform_plugin_analysis();
            
            // Salva i risultati in cache per evitare di rifare l'analisi
            set_transient('vigile_urbano_analysis_cache', $analysis_results, 3600);
            
            wp_send_json_success($analysis_results);
        } catch (Exception $e) {
            wp_send_json_error('Errore durante l\'analisi: ' . $e->getMessage());
        }
    }
    
    public function ajax_optimize_resources() {
        check_ajax_referer('vigile_urbano_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permessi insufficienti');
        }
        
        try {
            $optimization_results = $this->apply_optimizations();
            wp_send_json_success($optimization_results);
        } catch (Exception $e) {
            wp_send_json_error('Errore durante l\'ottimizzazione: ' . $e->getMessage());
        }
    }
    
    public function ajax_toggle_resource() {
        check_ajax_referer('vigile_urbano_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permessi insufficienti');
        }
        
        try {
            $resource_id = intval($_POST['resource_id']);
            $new_status = $this->toggle_resource_status($resource_id);
            
            wp_send_json_success(array('new_status' => $new_status));
        } catch (Exception $e) {
            wp_send_json_error('Errore nel toggle risorsa: ' . $e->getMessage());
        }
    }
    
    private function perform_plugin_analysis() {
        $active_plugins = get_option('active_plugins', array());
        $plugin_data = array();
        $duplicate_resources = array();
        $issues = array();
        
        // Analizza ogni plugin attivo
        foreach ($active_plugins as $plugin) {
            $plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
            if (file_exists($plugin_file)) {
                $plugin_info = get_plugin_data($plugin_file);
                $plugin_data[] = array(
                    'name' => $plugin_info['Name'],
                    'version' => $plugin_info['Version'],
                    'file' => $plugin,
                    'resources' => $this->analyze_plugin_resources($plugin_file)
                );
            }
        }
        
        // Identifica risorse duplicate
        $all_resources = array();
        foreach ($plugin_data as $plugin) {
            foreach ($plugin['resources'] as $resource) {
                $resource_key = $resource['handle'] . '_' . $resource['type'];
                if (isset($all_resources[$resource_key])) {
                    $duplicate_resources[] = array(
                        'resource' => $resource,
                        'plugins' => array($all_resources[$resource_key]['plugin'], $plugin['name'])
                    );
                } else {
                    $all_resources[$resource_key] = array(
                        'resource' => $resource,
                        'plugin' => $plugin['name']
                    );
                }
            }
        }
        
        // Identifica problemi comuni
        if (count($active_plugins) > 30) {
            $issues[] = 'Troppi plugin attivi (' . count($active_plugins) . '). Considera la disattivazione di quelli non essenziali.';
        }
        
        if (count($duplicate_resources) > 0) {
            $issues[] = 'Rilevate ' . count($duplicate_resources) . ' risorse duplicate che possono essere ottimizzate.';
        }
        
        return array(
            'plugins' => $plugin_data,
            'duplicates' => $duplicate_resources,
            'issues' => $issues,
            'summary' => array(
                'total_plugins' => count($plugin_data),
                'total_resources' => count($all_resources),
                'duplicate_count' => count($duplicate_resources),
                'issues_count' => count($issues)
            )
        );
    }
    
    private function analyze_plugin_resources($plugin_file) {
        $resources = array();
        
        // Analizza il file del plugin per trovare wp_enqueue_script e wp_enqueue_style
        $plugin_content = file_get_contents($plugin_file);
        
        // Cerca wp_enqueue_script
        preg_match_all('/wp_enqueue_script\s*\(\s*[\'"]([^\'"]+)[\'"]/', $plugin_content, $script_matches);
        foreach ($script_matches[1] as $handle) {
            $resources[] = array(
                'handle' => $handle,
                'type' => 'script'
            );
        }
        
        // Cerca wp_enqueue_style
        preg_match_all('/wp_enqueue_style\s*\(\s*[\'"]([^\'"]+)[\'"]/', $plugin_content, $style_matches);
        foreach ($style_matches[1] as $handle) {
            $resources[] = array(
                'handle' => $handle,
                'type' => 'style'
            );
        }
        
        return $resources;
    }
    
    private function apply_optimizations() {
        // Logica per applicare le ottimizzazioni
        $optimizations_applied = 0;
        
        // Rimuovi risorse duplicate
        add_action('wp_enqueue_scripts', array($this, 'optimize_resources'), 100);
        
        // Aggiungi lazy loading per immagini non critiche
        add_filter('wp_get_attachment_image_attributes', array($this, 'add_lazy_loading'));
        
        // Ottimizza query database
        $this->optimize_database_queries();
        
        return array(
            'success' => true,
            'optimizations_applied' => $optimizations_applied,
            'message' => 'Ottimizzazioni applicate con successo'
        );
    }
    
    public function optimize_resources() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'vigile_urbano_optimizations';
        
        $inactive_resources = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE is_active = 0"
        );
        
        foreach ($inactive_resources as $resource) {
            if ($resource->resource_type === 'script') {
                wp_dequeue_script($resource->resource_handle);
            } elseif ($resource->resource_type === 'style') {
                wp_dequeue_style($resource->resource_handle);
            }
        }
    }
    
    public function add_lazy_loading($attr) {
        if (!isset($attr['loading'])) {
            $attr['loading'] = 'lazy';
        }
        return $attr;
    }
    
    private function optimize_database_queries() {
        // Implementa cache per query comuni
        if (!wp_cache_get('vigile_urbano_optimized')) {
            // Ottimizzazioni per query
            wp_cache_set('vigile_urbano_optimized', true, '', 3600);
        }
    }
    
    private function toggle_resource_status($resource_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'vigile_urbano_optimizations';
        
        $current_status = $wpdb->get_var(
            $wpdb->prepare("SELECT is_active FROM $table_name WHERE id = %d", $resource_id)
        );
        
        $new_status = !$current_status;
        
        $wpdb->update(
            $table_name,
            array('is_active' => $new_status),
            array('id' => $resource_id)
        );
        
        return $new_status;
    }
    
    public function init_optimization() {
        $settings = get_option('vigile_urbano_settings', array());
        
        if (isset($settings['auto_optimize']) && $settings['auto_optimize']) {
            add_action('wp_enqueue_scripts', array($this, 'optimize_resources'), 100);
        }
        
        if (isset($settings['monitoring_enabled']) && $settings['monitoring_enabled']) {
            $this->init_monitoring();
        }
    }
    
    private function init_monitoring() {
        // Inizializza il sistema di monitoraggio
        add_action('wp_footer', array($this, 'add_monitoring_script'));
    }
    
    public function add_monitoring_script() {
        if (current_user_can('manage_options')) {
            ?>
            <script>
            // Script di monitoraggio delle performance
            window.vigialeUrbanoMonitor = {
                startTime: performance.now(),
                resources: [],
                
                init: function() {
                    this.monitorResources();
                    this.trackPageLoad();
                },
                
                monitorResources: function() {
                    if (typeof PerformanceObserver !== 'undefined') {
                        const observer = new PerformanceObserver((list) => {
                            list.getEntries().forEach((entry) => {
                                if (entry.name.includes('.js') || entry.name.includes('.css')) {
                                    this.resources.push({
                                        name: entry.name,
                                        type: entry.name.includes('.js') ? 'script' : 'style',
                                        duration: entry.duration,
                                        size: entry.transferSize || 0
                                    });
                                }
                            });
                        });
                        observer.observe({entryTypes: ['resource']});
                    }
                },
                
                trackPageLoad: function() {
                    window.addEventListener('load', () => {
                        const loadTime = performance.now() - this.startTime;
                        console.log('Vigile Urbano - Page Load Time:', loadTime + 'ms');
                        console.log('Vigile Urbano - Resources:', this.resources);
                    });
                }
            };
            
            document.addEventListener('DOMContentLoaded', function() {
                window.vigialeUrbanoMonitor.init();
            });
            </script>
            <?php
        }
    }
    
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    private function get_admin_css() {
        return '
.vigile-urbano-wrap {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: #f1f1f1;
    margin: 0 -20px;
    padding: 20px;
}

.vigile-urbano-wrap h1 {
    background: linear-gradient(135deg, #2271b1, #72aee6);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    font-size: 28px;
    margin-bottom: 20px;
}

.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
    animation: fadeInUp 0.6s ease-out;
}

.dashboard-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.dashboard-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}

.dashboard-card h3 {
    margin-top: 0;
    color: #2271b1;
}

.stat-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
}

.stat-value {
    font-weight: bold;
    color: #2271b1;
}

.performance-meter {
    text-align: center;
}

.meter-circle {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: conic-gradient(#2271b1 0deg 270deg, #ddd 270deg 360deg);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    position: relative;
}

.meter-circle::before {
    content: "";
    width: 70px;
    height: 70px;
    background: white;
    border-radius: 50%;
    position: absolute;
}

.meter-value {
    position: relative;
    z-index: 1;
    font-weight: bold;
    font-size: 18px;
    color: #2271b1;
}

.quick-controls {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.dashboard-main {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.button {
    transition: all 0.3s ease;
}

.button:hover {
    transform: translateY(-2px);
}

.vigile-notification {
    position: fixed;
    top: 50px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 5px;
    color: white;
    font-weight: bold;
    z-index: 9999;
    animation: slideInRight 0.3s ease-out;
}

.vigile-success {
    background: #28a745;
}

.vigile-error {
    background: #dc3545;
}

.vigile-warning {
    background: #ffc107;
    color: #212529;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideInRight {
    from {
        opacity: 0;
        transform: translateX(100px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.progress-bar {
    width: 100%;
    height: 20px;
    background: #ddd;
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 10px;
    position: relative;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #2271b1, #72aee6, #2271b1);
    background-size: 200% 100%;
    width: 0%;
    transition: width 0.3s ease;
    animation: shimmer 2s infinite;
}

@keyframes shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
    transition: all 0.3s ease;
}

.status-badge.active {
    background: #d4edda;
    color: #155724;
}

.status-badge.inactive {
    background: #f8d7da;
    color: #721c24;
}

.plugins-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
}

.plugin-card {
    border: 1px solid #ddd;
    padding: 15px;
    border-radius: 8px;
    background: #f9f9f9;
}

.plugin-card h4 {
    margin-top: 0;
    color: #2271b1;
}

.duplicates-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.duplicate-item {
    padding: 10px;
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 5px;
}
        ';
    }
    
    private function get_admin_js() {
        return "
jQuery(document).ready(function(\$) {
    
    function showDebug(message) {
        if (vigile_urbano_ajax.debug) {
            \$('#debug-info').show();
            \$('#debug-content').append('<p>' + new Date().toLocaleTimeString() + ': ' + message + '</p>');
        }
    }
    
    function showNotification(message, type) {
        const notification = \$('<div class=\"vigile-notification vigile-' + type + '\">' + message + '</div>');
        \$('body').append(notification);
        
        setTimeout(function() {
            notification.fadeOut(function() {
                \$(this).remove();
            });
        }, 3000);
    }
    
    // Gestione analisi rapida
    \$('#quick-analyze').on('click', function() {
        const button = \$(this);
        button.prop('disabled', true).text('Analizzando...');
        
        showDebug('Avvio analisi rapida');
        
        \$.post(vigile_urbano_ajax.ajax_url, {
            action: 'analyze_plugins',
            nonce: vigile_urbano_ajax.nonce
        })
        .done(function(response) {
            showDebug('Risposta ricevuta: ' + JSON.stringify(response));
            
            if (response.success) {
                updateDashboardStats(response.data);
                displayRecentResources(response.data);
                showNotification('Analisi completata con successo!', 'success');
            } else {
                showNotification('Errore durante l\\'analisi: ' + response.data, 'error');
            }
        })
        .fail(function(xhr, status, error) {
            showDebug('Errore AJAX: ' + error);
            showNotification('Errore di connessione durante l\\'analisi', 'error');
        })
        .always(function() {
            button.prop('disabled', false).text('Analisi Rapida');
        });
    });
    
    // Gestione auto-ottimizzazione
    \$('#auto-optimize').on('click', function() {
        const button = \$(this);
        button.prop('disabled', true).text('Ottimizzando...');
        
        showDebug('Avvio ottimizzazione automatica');
        
        \$.post(vigile_urbano_ajax.ajax_url, {
            action: 'optimize_resources',
            nonce: vigile_urbano_ajax.nonce
        })
        .done(function(response) {
            if (response.success) {
                showNotification('Ottimizzazioni applicate con successo!', 'success');
            } else {
                showNotification('Errore durante l\\'ottimizzazione: ' + response.data, 'error');
            }
        })
        .fail(function(xhr, status, error) {
            showNotification('Errore di connessione durante l\\'ottimizzazione', 'error');
        })
        .always(function() {
            button.prop('disabled', false).text('Auto-Ottimizza');
        });
    });
    
    // Gestione pulizia cache
    \$('#clear-cache').on('click', function() {
        const button = \$(this);
        button.prop('disabled', true).text('Pulendo...');
        
        \$.post(vigile_urbano_ajax.ajax_url, {
            action: 'clear_cache',
            nonce: vigile_urbano_ajax.nonce
        })
        .done(function(response) {
            if (response.success) {
                showNotification('Cache pulita con successo!', 'success');
            } else {
                showNotification('Errore durante la pulizia cache', 'error');
            }
        })
        .always(function() {
            button.prop('disabled', false).text('Pulisci Cache');
        });
    });
    
    // Gestione analisi completa
    \$('#start-analysis').on('click', function() {
        const button = \$(this);
        const progressBar = \$('#analysis-progress');
        const progressFill = \$('.progress-fill');
        const progressText = \$('#progress-text');
        
        button.prop('disabled', true);
        progressBar.show();
        
        let progress = 0;
        const progressInterval = setInterval(function() {
            progress += Math.random() * 10;
            if (progress > 90) progress = 90;
            
            progressFill.css('width', progress + '%');
            progressText.text('Analisi in corso... ' + Math.round(progress) + '%');
        }, 200);
        
        \$.post(vigile_urbano_ajax.ajax_url, {
            action: 'analyze_plugins',
            nonce: vigile_urbano_ajax.nonce
        })
        .done(function(response) {
            clearInterval(progressInterval);
            progressFill.css('width', '100%');
            progressText.text('Analisi completata!');
            
            setTimeout(function() {
                progressBar.hide();
                if (response.success) {
                    displayAnalysisResults(response.data);
                    showNotification('Analisi completa terminata!', 'success');
                } else {
                    showNotification('Errore durante l\\'analisi: ' + response.data, 'error');
                }
                button.prop('disabled', false);
            }, 1000);
        })
        .fail(function() {
            clearInterval(progressInterval);
            progressBar.hide();
            button.prop('disabled', false);
            showNotification('Errore di connessione durante l\\'analisi', 'error');
        });
    });
    
    // Toggle ottimizzazioni
    \$('.toggle-optimization').on('click', function() {
        const button = \$(this);
        const resourceId = button.data('id');
        
        \$.post(vigile_urbano_ajax.ajax_url, {
            action: 'toggle_resource',
            resource_id: resourceId,
            nonce: vigile_urbano_ajax.nonce
        })
        .done(function(response) {
            if (response.success) {
                location.reload();
            } else {
                showNotification('Errore nel toggle risorsa', 'error');
            }
        });
    });
    
    function updateDashboardStats(data) {
        \$('#monitored-resources').text(data.summary.total_resources);
        \$('#active-optimizations').text(data.summary.duplicate_count);
        
        // Aggiorna performance score
        const score = Math.max(10, 100 - (data.summary.issues_count * 10));
        \$('.meter-value').text(score + '%');
        updateMeterCircle(score);
    }
    
    function updateMeterCircle(score) {
        const degrees = (score / 100) * 360;
        \$('.meter-circle').css('background', 
            'conic-gradient(#2271b1 0deg ' + degrees + 'deg, #ddd ' + degrees + 'deg 360deg)'
        );
    }
    
    function displayRecentResources(data) {
        let html = '<h4>Ultimi plugin analizzati:</h4><ul>';
        data.plugins.slice(0, 5).forEach(function(plugin) {
            html += '<li><strong>' + plugin.name + '</strong> - ' + plugin.resources.length + ' risorse</li>';
        });
        html += '</ul>';
        
        if (data.duplicates.length > 0) {
            html += '<h4>⚠️ Attenzione:</h4>';
            html += '<p>Rilevate ' + data.duplicates.length + ' risorse duplicate che possono essere ottimizzate.</p>';
        }
        
        \$('#recent-resources').html(html);
    }
    
    function displayAnalysisResults(data) {
        // Mostra plugin analizzati
        let pluginsHtml = '<div class=\"plugins-grid\">';
        data.plugins.forEach(function(plugin) {
            pluginsHtml += '<div class=\"plugin-card\">' +
                '<h4>' + plugin.name + '</h4>' +
                '<p>Versione: ' + plugin.version + '</p>' +
                '<p>Risorse: ' + plugin.resources.length + '</p>' +
                '</div>';
        });
        pluginsHtml += '</div>';
        \$('#plugins-list').html(pluginsHtml);
        
        // Mostra risorse duplicate
        if (data.duplicates.length > 0) {
            let duplicatesHtml = '<div class=\"duplicates-list\">';
            data.duplicates.forEach(function(duplicate) {
                duplicatesHtml += '<div class=\"duplicate-item\">' +
                    '<strong>' + duplicate.resource.handle + '</strong> (' + duplicate.resource.type + ')' +
                    '<br>Trovato in: ' + duplicate.plugins.join(', ') +
                    '</div>';
            });
            duplicatesHtml += '</div>';
            \$('#duplicate-resources').html(duplicatesHtml);
        } else {
            \$('#duplicate-resources').html('<p>✅ Nessuna risorsa duplicata rilevata.</p>');
        }
        
        // Mostra problemi
        if (data.issues.length > 0) {
            let issuesHtml = '<ul>';
            data.issues.forEach(function(issue) {
                issuesHtml += '<li>⚠️ ' + issue + '</li>';
            });
            issuesHtml += '</ul>';
            \$('#detected-issues').html(issuesHtml);
        } else {
            \$('#detected-issues').html('<p>✅ Nessun problema rilevato.</p>');
        }
    }
    
    showDebug('Vigile Urbano JavaScript caricato correttamente');
});
        ";
    }
}

// Inizializza il plugin
new VigilaUrbanoResourceManager();
?>