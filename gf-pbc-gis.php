<?php
/**
 * Plugin Name:       SFLWA Gravity Forms PBC Property Appraiser Lookup
 * Plugin URI:        https://github.com/sflwa/gf-pbc-gis/
 * Description:       Verifies property ownership via PBC GIS. High-priority auto-trigger and dual-owner sidebar display.
 * Version:           1.9.3
 * Author:            South Florida Web Advisors
 * Text Domain:       gf-pbc-gis
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// 1. GLOBAL BULK ACTION UI
add_filter( 'gform_entry_list_bulk_actions', 'sflwa_pbc_add_bulk_action', 10, 2 );
function sflwa_pbc_add_bulk_action( $actions, $form_id ) {
    $form_meta = RGFormsModel::get_form_meta( $form_id );
    if ( isset( $form_meta['gf-pbc-gis'] ) && ! empty( $form_meta['gf-pbc-gis']['enabled'] ) ) {
        $actions['pbc_bulk_lookup'] = 'Trigger PBC Lookup';
    }
    return $actions;
}

// 2. GLOBAL BULK ACTION HANDLER
add_action( 'gform_entry_list_action', 'sflwa_pbc_handle_bulk_action', 10, 3 );
function sflwa_pbc_handle_bulk_action( $action, $entries, $form_id ) {
    if ( $action !== 'pbc_bulk_lookup' ) return;
    $addon = SFLWAPBCAddOn::get_instance();
    $form = GFAPI::get_form( $form_id );
    foreach ( $entries as $entry_id ) {
        $entry = GFAPI::get_entry( $entry_id );
        if ( ! is_wp_error( $entry ) ) {
            $addon->get_pbc_data_for_entry( $form, $entry, true );
        }
    }
    GFCommon::add_message( count( $entries ) . ' entries processed.' );
}

// 3. THE PLUGIN LOADER
add_action( 'gform_loaded', array( 'SFLWA_PBC_Loader', 'load' ), 5 );

class SFLWA_PBC_Loader {
    public static function load() {
        if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) return;
        GFAddOn::register( 'SFLWAPBCAddOn' );
    }
}

class SFLWAPBCAddOn extends GFAddOn {
    protected $_version = '1.9.3';
    protected $_slug = 'gf-pbc-gis';
    protected $_path = 'gf-pbc-gis/gf-pbc-gis.php';
    protected $_full_path = __FILE__;
    protected $_title = 'PBC Property Verification';
    protected $_short_title = 'PBC Verification';

    private static $_instance = null;
    public static function get_instance() {
        if ( self::$_instance == null ) self::$_instance = new self();
        return self::$_instance;
    }

    public function init() {
        parent::init();
        add_filter( 'gform_entry_detail_meta_boxes', array( $this, 'register_meta_box' ), 10, 3 );
        add_filter( 'gform_replace_merge_tags', array( $this, 'replace_merge_tags' ), 10, 7 );
        add_filter( 'gform_admin_merge_tags', array( $this, 'add_merge_tags' ), 10, 3 );
        add_filter( 'gform_entry_meta', array( $this, 'register_entry_meta' ), 10, 2 );

        // High-priority hook to ensure trigger happens before emails go out
        add_action( 'gform_after_submission', array( $this, 'maybe_trigger_lookup' ), 9, 2 );

        if ( rgget('page') == 'gf_settings' && rgget('subview') == $this->_slug && rgget('action') == 'migrate' ) {
            add_action( 'gform_addon_settings_content', array( $this, 'render_migration_page' ) );
        }
    }

    // Helper to verify settings before running
    public function maybe_trigger_lookup( $entry, $form ) {
        $settings = $this->get_form_settings( $form );
        if ( ! empty( $settings['enabled'] ) ) {
            $this->get_pbc_data_for_entry( $form, $entry );
        }
    }

    public function form_settings_fields( $form ) {
        return array(
            array(
                'title'  => 'PBC API Settings',
                'fields' => array(
                    array('label' => 'Enable PBC Verification', 'type' => 'toggle', 'name' => 'enabled'),
                    array('label' => 'Condo Mode', 'type' => 'toggle', 'name' => 'condo_mode'),
                    array(
                        'label'      => 'Field Mapping',
                        'type'       => 'field_map',
                        'name'       => 'field_mapping',
                        'field_type' => array( 'text', 'address', 'select', 'name', 'number' ),
                        'field_map'  => array(
                            array( 'name' => 'house_number',   'label' => 'Street/Unit Number' ),
                            array( 'name' => 'street_name',    'label' => 'Street Name / Full Address' ),
                            array( 'name' => 'resident_name',  'label' => 'Resident Name' ),
                        ),
                    ),
                    array('label' => 'Static Base Address (Manual Overwrite)', 'type' => 'text', 'name' => 'static_address', 'placeholder' => 'Example: 2600 NE 1st Ln'),
                    array('label' => 'Enable Migration Mode', 'type' => 'toggle', 'name' => 'enable_migration_mode'),
                ),
            ),
            array(
                'title' => 'Reference',
                'fields' => array(
                    array('type' => 'html', 'name' => 'mt_ref', 'callback' => array( $this, 'settings_merge_tags' )),
                )
            )
        );
    }

    public function get_pbc_data_for_entry( $form, $entry, $force = false ) {
        $settings = $this->get_form_settings( $form );
        
        $name_id   = rgar( $settings, "field_mapping_resident_name" );
        $house_id  = rgar( $settings, "field_mapping_house_number" );
        $street_id = rgar( $settings, "field_mapping_street_name" );

        $fname = rgar( $entry, $name_id . '.3' ); 
        $lname = rgar( $entry, $name_id . '.6' );
        $h_val = rgar( $entry, $house_id );
        $s_val = rgar( $entry, $street_id );
        $static_base = rgar( $settings, "static_address" );

        $search = ( ! empty( $settings['condo_mode'] ) ) 
            ? strtoupper(trim( ( ! empty($static_base) ? $static_base : $s_val ) . " " . $h_val ))
            : strtoupper(trim($h_val . " " . $s_val));

        $where = "SITE_ADDR_STR LIKE '" . esc_sql($search) . "%'";
        $api_url = add_query_arg( array( 'where' => $where, 'outFields' => 'PARCEL_NUMBER,OWNER_NAME1,OWNER_NAME2', 'f' => 'json' ), "https://services1.arcgis.com/ZWOoUZbtaYePLlPw/arcgis/rest/services/Property_Information_Table/FeatureServer/0/query" );
        $res = wp_remote_get( $api_url, array('timeout' => 25) );
        $data = json_decode( wp_remote_retrieve_body($res), true );

        if ( ! empty($data['features']) ) {
            $attr = $data['features'][0]['attributes'];
            $o1 = $attr['OWNER_NAME1'] ?? ''; 
            $o2 = $attr['OWNER_NAME2'] ?? '';
            $pcn = $attr['PARCEL_NUMBER'] ?? '';
            $match = $this->fuzzy_match($fname, $lname, $o1) || $this->fuzzy_match($fname, $lname, $o2);
            
            gform_update_meta( $entry['id'], 'pbc_status', $match ? 'Matched' : 'Mismatch' );
            gform_update_meta( $entry['id'], 'pbc_owner_1', $o1 );
            gform_update_meta( $entry['id'], 'pbc_owner_2', $o2 );
            gform_update_meta( $entry['id'], 'pbc_pcn', $pcn );
        } else {
            gform_update_meta( $entry['id'], 'pbc_status', 'Not Found' );
            gform_update_meta( $entry['id'], 'pbc_owner_1', '' );
            gform_update_meta( $entry['id'], 'pbc_owner_2', '' );
            gform_update_meta( $entry['id'], 'pbc_pcn', '' );
        }

        GFAPI::add_note( $entry['id'], 0, 'PBC Bridge', "Query: $where\nOwner Match Attempt: $fname $lname" );
        return true;
    }

    public function replace_merge_tags($text, $form, $entry, $url_encode, $esc_html, $nl2br, $format) {
        if ( strpos( $text, '{pbc_raw_data}' ) === false ) return $text;
        
        $settings = $this->get_form_settings( $form );
        $name_id  = rgar( $settings, "field_mapping_resident_name" );
        $fname    = rgar( $entry, $name_id . '.3' ); 
        $lname    = rgar( $entry, $name_id . '.6' );
        
        $status = gform_get_meta( $entry['id'], 'pbc_status' ) ?: 'Not Run';
        $pcn    = gform_get_meta( $entry['id'], 'pbc_pcn' );
        $o1     = gform_get_meta( $entry['id'], 'pbc_owner_1' );
        $o2     = gform_get_meta( $entry['id'], 'pbc_owner_2' );
        
        $search_name = urlencode( strtoupper( trim( $lname . " " . $fname ) ) );
        $name_url = "https://pbcpao.gov/MasterSearch/SearchResults?propertyType=RE&searchvalue=$search_name";
        $color = ($status === 'Matched') ? '#27ae60' : '#e74c3c';
        
        $html = "<table width='100%' border='0' cellpadding='0' cellspacing='0' style='background-color:#f4f7f9; border-radius:4px; font-family:sans-serif; margin-bottom:20px;'>
                <tr><td style='padding:15px; border-bottom:1px solid #e1e8ed;'><span style='font-size:11px; font-weight:bold; text-transform:uppercase; color:#5e6d77; display:block; margin-bottom:5px;'>PBC Property Verification</span><span style='font-size:18px; font-weight:bold; color:$color;'>$status</span></td></tr>";
        
        if ( $pcn ) {
            $owners = trim($o1 . ($o2 ? ' & ' . $o2 : ''));
            $html .= "<tr><td style='padding:15px; background-color:#ffffff;'><table width='100%' border='0'>
                      <tr><td style='padding-bottom:10px; border-bottom:1px solid #f0f3f5;'><span style='font-size:11px; font-weight:bold; color:#919da5;'>OFFICIAL OWNER(S)</span><br><span style='font-size:14px; color:#2c3e50;'>$owners</span></td></tr>
                      <tr><td style='padding-top:10px; padding-bottom:15px;'><span style='font-size:11px; font-weight:bold; color:#919da5;'>PARCEL CONTROL NUMBER (PCN)</span><br><span style='font-size:14px; color:#2c3e50;'>$pcn</span></td></tr>
                      <tr><td><a href='https://pbcpao.gov/Property/Details?parcelId=$pcn' target='_blank' style='display:inline-block; background-color:#2c3e50; color:#fff; padding:10px 15px; text-decoration:none; border-radius:4px; font-size:13px; font-weight:bold;'>View Official Record &rarr;</a>";
            if ( $status === 'Mismatch' ) {
                $html .= " <a href='$name_url' target='_blank' style='display:inline-block; background-color:#e74c3c; color:#fff; padding:10px 15px; text-decoration:none; border-radius:4px; font-size:13px; font-weight:bold; margin-top:5px;'>PAPA Search by Name</a>";
            }
            $html .= "</td></tr></table></td></tr>";
        } else {
            $html .= "<tr><td style='padding:15px; background-color:#ffffff;'><p style='font-size:13px; color:#555;'>No record found at this address.</p>
                      <a href='$name_url' target='_blank' style='display:inline-block; background-color:#e74c3c; color:#fff; padding:10px 15px; text-decoration:none; border-radius:4px; font-size:13px; font-weight:bold;'>PBC PAPA Search by Name &rarr;</a></td></tr>";
        }
        $html .= "</table>";
        return str_replace( '{pbc_raw_data}', $html, $text );
    }

    public function add_details_meta_box($args) {
        $entry = $args['entry']; 
        $action = $this->_slug . '_process_lookup';

        if ( rgpost( 'action' ) == $action ) {
            check_admin_referer( 'gforms_save_entry', 'gforms_save_entry' );
            $this->get_pbc_data_for_entry( $args['form'], $entry, true ); 
            $entry = GFAPI::get_entry( $entry['id'] ); 
        }

        $settings = $this->get_form_settings( $args['form'] );
        $name_id  = rgar( $settings, "field_mapping_resident_name" );
        $fname    = rgar( $entry, $name_id . '.3' ); 
        $lname    = rgar( $entry, $name_id . '.6' );

        $status = gform_get_meta($entry['id'],'pbc_status')?:'Not Run'; 
        $pcn    = gform_get_meta($entry['id'],'pbc_pcn'); 
        $o1     = gform_get_meta($entry['id'],'pbc_owner_1');
        $o2     = gform_get_meta($entry['id'],'pbc_owner_2');
        $color  = ($status==='Matched')?'#27ae60':'#e74c3c';
        
        $search_name = urlencode( strtoupper( trim( $lname . " " . $fname ) ) ); 
        $name_url = "https://pbcpao.gov/MasterSearch/SearchResults?propertyType=RE&searchvalue=$search_name";
        
        echo "<div style='line-height:1.6;'><strong>Status:</strong> <span style='color:$color; font-weight:bold;'>$status</span><br>";
        if($pcn) { 
            echo "<strong>Owner 1:</strong> " . esc_html($o1) . "<br>";
            if(!empty($o2)) echo "<strong>Owner 2:</strong> " . esc_html($o2) . "<br>";
            echo "<strong>PCN:</strong> " . esc_html($pcn) . "<br>";
            
            echo sprintf( '<div style="margin-top:10px;"><input type="submit" value="Re-Run Lookup" class="button" onclick="jQuery(\'#action\').val(\'%s\');" style="width:100%%; margin-bottom:5px;" />', $action );
            echo "<a href='https://pbcpao.gov/Property/Details?parcelId=$pcn' target='_blank' class='button button-primary' style='display:block; text-align:center;'>View Official Record</a></div>";
            
            if ($status === 'Mismatch') {
                echo "<div style='margin-top:5px;'><a href='$name_url' target='_blank' class='button' style='display:block; text-align:center; background:#e74c3c; color:#fff; border:none;'>PAPA Search by Name</a></div>";
            }
        } else {
            echo sprintf( '<div style="margin-top:10px;"><input type="submit" value="Re-Run Lookup" class="button" onclick="jQuery(\'#action\').val(\'%s\');" style="width:100%%; margin-bottom:5px;" />', $action );
            echo "<a href='$name_url' target='_blank' class='button' style='display:block; text-align:center; background:#e74c3c; color:#fff; border:none;'>PBC PAPA Search by Name</a></div>";
        }
        echo "</div>";
    }

    private function fuzzy_match($f, $l, $o) {
        $o = strtolower(trim($o)); if(empty($f)||empty($l)||empty($o)) return false;
        $o = str_replace(array(',','&'),' ',$o); $f=strtolower(trim($f)); $l=strtolower(trim($l));
        return (strpos($o,$f.' '.$l)!==false || strpos($o,$l.' '.$f)!==false);
    }

    public function render_migration_page() {
        $form = $this->get_current_form(); echo "<h2>Legacy Migration: " . esc_html($form['title']) . "</h2>";
        if ( ! rgpost('run_process') ) {
            echo '<form method="post" style="background:#fff; padding:20px; border:1px solid #ccd0d4;">'; wp_nonce_field( 'pbc_migrate', 'pbc_migrate_nonce' );
            echo '<table class="form-table"><tr><th>Source (Full Address)</th><td>' . $this->get_field_dropdown('source_id') . '</td></tr><tr><th>Target (Number)</th><td>' . $this->get_field_dropdown('num_id') . '</td></tr><tr><th>Target (Street)</th><td>' . $this->get_field_dropdown('street_id') . '</td></tr></table><p><input type="submit" name="run_process" value="Start Migration" class="button button-primary" /></p></form>';
        } else {
            check_admin_referer( 'pbc_migrate', 'pbc_migrate_nonce' ); $this->execute_migration_logic( rgpost('source_id'), rgpost('num_id'), rgpost('street_id') );
        }
    }
    private function get_field_dropdown($name) {
        $form = $this->get_current_form(); $html = "<select name='$name'><option value=''>-- Select --</option>";
        foreach($form['fields'] as $field) { $html .= "<option value='{$field->id}'>{$field->label} (ID: {$field->id})</option>"; }
        return $html . "</select>";
    }
    private function execute_migration_logic($source_id, $num_id, $street_id) {
        $form = $this->get_current_form(); $entries = GFAPI::get_entries( $form['id'], array('status'=>'active'), null, array('page_size'=>1000) );
        $suffix_map = array('DRIVE'=>'Dr','DR'=>'Dr','STREET'=>'St','ST'=>'St','AVENUE'=>'Ave','AVE'=>'Ave','LANE'=>'Ln','LN'=>'Ln','ROAD'=>'Rd','RD'=>'Rd','COURT'=>'Ct','CT'=>'Ct','BOULEVARD'=>'Blvd','BLVD'=>'Blvd','WAY'=>'Way','CIRCLE'=>'Cir','CIR'=>'Cir');
        $directionals = array( 'NE', 'NW', 'SE', 'SW', 'N', 'S', 'E', 'W' ); $count = 0;
        echo "<div style='background:#fff; padding:20px; border:1px solid #ccd0d4;'>";
        foreach ($entries as $entry) {
            $raw = trim(rgar($entry, $source_id));
            if (preg_match('/^(\d+)\s+(.*)$/', $raw, $matches)) {
                $num = $matches[1]; $words = preg_split('/[\s,]+/', trim($matches[2])); $temp = array();
                foreach ($words as $word) { $upper = strtoupper($word); if (isset($suffix_map[$upper])) { $temp[] = $suffix_map[$upper]; break; } elseif (in_array($upper, $directionals)) { $temp[] = $upper; } else { $temp[] = ucwords(strtolower($word)); } }
                $street = implode(' ', $temp); GFAPI::update_entry_field($entry['id'], $num_id, $num); GFAPI::update_entry_field($entry['id'], $street_id, $street); $count++;
            }
        }
        echo "<h3>Migration Complete: $count updated.</h3><a href='".remove_query_arg('action')."' class='button'>&larr; Back</a></div>";
    }

    public function add_merge_tags($mt, $form_id, $fields) { $mt[] = array('group'=>'pbc_verification','label'=>'PBC: Result Box','tag'=>'{pbc_raw_data}'); return $mt; }
    public function register_meta_box($mb, $entry, $form) { $settings = $this->get_form_settings($form); if(!empty($settings['enabled'])) { $mb[$this->_slug] = array('title'=>$this->get_short_title(),'callback' => array($this,'add_details_meta_box'),'context'=>'side'); } return $mb; }
    public function settings_merge_tags() { echo '<div style="background:#fcfcfc; padding:20px; border:1px solid #e2e4e7; border-radius:4px; margin-top:20px;"><h4>Merge Tag Guide</h4><p>Use <code>{pbc_raw_data}</code> for the formatted result box.</p></div>'; }
    public function register_entry_meta($em, $fid) { $em['pbc_status'] = array('label'=>'Match Status','is_numeric'=>false,'is_default_column'=>false,'filter' => array('operators'=>array('is', 'isnot'),'choices'=>array(array('text'=>'Matched','value'=>'Matched'),array('text'=>'Mismatch','value'=>'Mismatch'),array('text'=>'Not Found','value'=>'Not Found'),array('text'=>'Not Run','value'=>'')))); return $em; }
}
