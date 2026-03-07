<?php
/**
 * Plugin Name:       SFLWA Gravity Forms PBC Property Appraiser Lookup
 * Plugin URI:        https://github.com/sflwa/gf-pbc-gis/
 * Description:       Verifies property ownership via PBC GIS for HOAs and Condos. Features unordered fuzzy matching and Condo Mode.
 * Version:           1.4.4
 * Requires at least: 6.9
 * Requires PHP:      8.3
 * Author:            South Florida Web Advisors
 * Author URI:        https://sflwa.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gf-pbc-gis
 * Domain Path:       /languages
 * Requires Plugins:  gravityforms
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'gform_loaded', array( 'SFLWA_PBC_Loader', 'load' ), 5 );

class SFLWA_PBC_Loader {
    public static function load() {
        if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) return;
        GFAddOn::register( 'SFLWAPBCAddOn' );
    }
}

class SFLWAPBCAddOn extends GFAddOn {

    protected $_version = '1.4.4';
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
    }

    public function register_entry_meta( $entry_meta, $form_id ) {
        $entry_meta['pbc_status'] = array(
            'label'             => 'Match Status',
            'is_numeric'        => false,
            'is_default_column' => true,
        );
        return $entry_meta;
    }

    public function add_merge_tags( $merge_tags, $form_id, $fields ) {
        $merge_tags[] = array( 'group' => 'pbc_verification', 'label' => 'PBC: Full Result Box', 'tag' => '{pbc_raw_data}' );
        $merge_tags[] = array( 'group' => 'pbc_verification', 'label' => 'PBC: Match Status', 'tag' => '{pbc_match_status}' );
        $merge_tags[] = array( 'group' => 'pbc_verification', 'label' => 'PBC: Parcel Number (PCN)', 'tag' => '{pbc_pcn}' );
        $merge_tags[] = array( 'group' => 'pbc_verification', 'label' => 'PBC: Owners List', 'tag' => '{pbc_owners}' );
        $merge_tags[] = array( 'group' => 'pbc_verification', 'label' => 'PBC: Master Search Link', 'tag' => '{pbc_search_url}' );
        return $merge_tags;
    }

    public function settings_merge_tags() {
        echo '<div style="background:#f9f9f9; padding:15px; border:1px solid #ddd; border-radius:4px; margin-top:10px;">';
        echo '<h4 style="margin-top:0;">Available Merge Tags</h4>';
        echo '<p style="font-size:12px; color:#666;">Copy these into your Notifications or Confirmations:</p>';
        echo '<ul style="margin:0; padding-left:20px; line-height:1.8;">';
        echo '<li><code>{pbc_match_status}</code> - Displays Matched, Mismatch, or Not Found</li>';
        echo '<li><code>{pbc_raw_data}</code> - Displays the full branded result table</li>';
        echo '<li><code>{pbc_pcn}</code> - Displays the Parcel Control Number</li>';
        echo '<li><code>{pbc_search_url}</code> - Direct link to Master Search results</li>';
        echo '</ul></div>';
    }

    public function register_meta_box( $meta_boxes, $entry, $form ) {
        $settings = $this->get_form_settings( $form );
        if ( ! empty( $settings['enabled'] ) ) {
            $meta_boxes[ $this->_slug ] = array(
                'title'    => $this->get_short_title(),
                'callback' => array( $this, 'add_details_meta_box' ),
                'context'  => 'side',
            );
        }
        return $meta_boxes;
    }

    public function add_details_meta_box( $args ) {
        $entry = $args['entry'];
        $action = $this->_slug . '_process_lookup';

        if ( rgpost( 'action' ) == $action ) {
            check_admin_referer( 'gforms_save_entry', 'gforms_save_entry' );
            $this->get_pbc_data_for_entry( $args['form'], $entry, true ); 
            $entry = GFAPI::get_entry( $entry['id'] ); 
        }

        $status = gform_get_meta( $entry['id'], 'pbc_status' ) ?: 'Not Run';
        $o1 = gform_get_meta( $entry['id'], 'pbc_owner_1' );
        $o2 = gform_get_meta( $entry['id'], 'pbc_owner_2' );
        $pcn    = gform_get_meta( $entry['id'], 'pbc_pcn' );
        
        $color = ( $status === 'Matched' ) ? '#27ae60' : '#e74c3c';
        echo '<div style="line-height:1.6;">';
        echo '<strong>Status:</strong> <span style="color:'.$color.'; font-weight:bold;">' . esc_html( $status ) . '</span><br>';
        if ( $pcn ) {
            echo '<strong>Owner 1:</strong> ' . esc_html( $o1 ) . '<br>';
            if ($o2) echo '<strong>Owner 2:</strong> ' . esc_html( $o2 ) . '<br>';
            echo '<strong>PCN:</strong> ' . esc_html( $pcn ) . '<br>';
        }
        echo '<div style="margin-top:10px;">';
        echo sprintf( '<input type="submit" value="Re-Run Lookup" class="button" onclick="jQuery(\'#action\').val(\'%s\');" style="width:100%%; margin-bottom:5px;" />', $action );
        if ( $pcn ) {
            echo sprintf( '<a href="https://pbcpao.gov/Property/Details?parcelId=%s" target="_blank" class="button button-primary" style="display:block; text-align:center;">View Record</a>', $pcn );
        }
        echo '</div></div>';
    }

    public function form_settings_fields( $form ) {
        return array(
            array(
                'title'  => 'PBC API Settings',
                'fields' => array(
                    array('label' => 'Enable PBC Verification', 'type' => 'toggle', 'name' => 'enabled'),
                    array('label' => 'Condo Mode', 'type' => 'toggle', 'name' => 'condo_mode'),
                    array('label' => 'Static Base Address', 'type' => 'text', 'name' => 'static_address', 'placeholder' => 'Example: 2600 NE 1st Ln'),
                    array(
                        'label'      => 'Field Mapping',
                        'type'       => 'field_map',
                        'name'       => 'field_mapping',
                        'field_type' => array( 'text', 'address', 'select', 'name' ),
                        'field_map'  => array(
                            array( 'name' => 'unit_number',   'label' => 'Unit Number Field' ),
                            array( 'name' => 'resident_name', 'label' => 'Resident Name Field' ),
                            array( 'name' => 'base_addr_field', 'label' => 'Full Address Field' ),
                        ),
                    ),
                    array('type' => 'html', 'name' => 'mt_ref', 'callback' => array( $this, 'settings_merge_tags' )),
                ),
            ),
        );
    }

    public function after_submission( $form, $entry ) {
        $this->get_pbc_data_for_entry( $form, $entry );
    }

    private function fuzzy_match( $first, $last, $official ) {
        $official = strtolower(trim($official));
        if (empty($first) || empty($last) || empty($official)) return false;
        $official = str_replace(array(',', '&'), ' ', $official);
        $first = strtolower(trim($first));
        $last  = strtolower(trim($last));
        $v1 = $first . ' ' . $last;
        $v2 = $last . ' ' . $first;
        return (strpos($official, $v1) !== false || strpos($official, $v2) !== false);
    }

    public function get_pbc_data_for_entry( $form, $entry, $force = false ) {
        $settings = $this->get_form_settings( $form );
        if ( empty( $settings['enabled'] ) ) return false;
        if ( ! $force && gform_get_meta( $entry['id'], 'pbc_pcn' ) ) return true;

        $name_field_id = rgar( $settings, "field_mapping_resident_name" );
        $fname = rgar( $entry, $name_field_id . '.3' ); 
        $lname = rgar( $entry, $name_field_id . '.6' );

        $unit = rgar( $entry, (string) rgar( $settings, "field_mapping_unit_number" ) );
        $base = ! empty($settings['static_address']) ? $settings['static_address'] : rgar( $entry, (string) rgar( $settings, "field_mapping_base_addr_field" ) );
        
        if ( empty($base) ) return false;

        if ( ! empty($settings['condo_mode']) ) {
            $full_string = strtoupper(trim($base . " " . $unit));
            $where = "SITE_ADDR_STR LIKE '" . esc_sql($full_string) . "%'";
        } else {
            $parts = explode(' ', trim($base), 2);
            $where = "STREET_NUMBER = " . intval($parts[0] ?? 0) . " AND STREET_NAME LIKE '" . esc_sql(strtoupper($parts[1] ?? '')) . "%'";
        }

        $api_url = add_query_arg( array( 'where' => $where, 'outFields' => 'PARCEL_NUMBER,OWNER_NAME1,OWNER_NAME2', 'f' => 'json' ), "https://services1.arcgis.com/ZWOoUZbtaYePLlPw/arcgis/rest/services/Property_Information_Table/FeatureServer/0/query" );
        $res = wp_remote_get( $api_url, array('timeout' => 25, 'user-agent' => 'Mozilla/5.0') );
        $data = json_decode( wp_remote_retrieve_body($res), true );

        if ( ! empty($data['features']) ) {
            $attr = $data['features'][0]['attributes'];
            $o1 = $attr['OWNER_NAME1'] ?? '';
            $o2 = $attr['OWNER_NAME2'] ?? '';
            $match = $this->fuzzy_match($fname, $lname, $o1) || $this->fuzzy_match($fname, $lname, $o2);
            
            gform_update_meta( $entry['id'], 'pbc_status', $match ? 'Matched' : 'Mismatch' );
            gform_update_meta( $entry['id'], 'pbc_owner_1', $o1 );
            gform_update_meta( $entry['id'], 'pbc_owner_2', $o2 );
            gform_update_meta( $entry['id'], 'pbc_pcn', $attr['PARCEL_NUMBER'] );
        } else {
            gform_update_meta( $entry['id'], 'pbc_status', 'Not Found' );
        }
        
        GFAPI::add_note( $entry['id'], 0, 'PBC Bridge', "Query: $where\nMatch Check: $fname $lname against $o1 / $o2" );
        return true;
    }

    public function replace_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
        if ( ! strpbrk($text, '{}') ) return $text;
        
        $status = gform_get_meta( $entry['id'], 'pbc_status' ) ?: 'Not Run';
        $o1 = gform_get_meta( $entry['id'], 'pbc_owner_1' ) ?: '';
        $o2 = gform_get_meta( $entry['id'], 'pbc_owner_2' ) ?: '';
        $pcn = gform_get_meta( $entry['id'], 'pbc_pcn' ) ?: 'N/A';
        $color = ($status === 'Matched') ? '#27ae60' : '#e74c3c';
        $owners_display = trim($o1 . ($o2 ? ' & ' . $o2 : ''));
        
        $html_summary = "
            <table width='100%' border='0' cellpadding='0' cellspacing='0' style='background-color: #f4f7f9; border-radius: 4px; font-family: sans-serif; margin-bottom: 20px;'>
                <tr><td style='padding: 15px; border-bottom: 1px solid #e1e8ed;'>
                    <span style='font-size: 11px; font-weight: bold; text-transform: uppercase; color: #5e6d77; display: block; margin-bottom: 5px;'>PBC Property Verification</span>
                    <span style='font-size: 18px; font-weight: bold; color: $color;'>$status</span>
                </td></tr>
                <tr><td style='padding: 15px; background-color: #ffffff;'>
                    <table width='100%' border='0'>
                        <tr><td style='padding-bottom: 10px; border-bottom: 1px solid #f0f3f5;'>
                            <span style='font-size: 11px; font-weight: bold; color: #919da5;'>OFFICIAL OWNER(S)</span><br>
                            <span style='font-size: 14px; color: #2c3e50;'>$owners_display</span>
                        </td></tr>
                        <tr><td style='padding-top: 10px;'>
                            <span style='font-size: 11px; font-weight: bold; color: #919da5;'>PARCEL CONTROL NUMBER (PCN)</span><br>
                            <span style='font-size: 14px; color: #2c3e50;'>$pcn</span>
                        </td></tr>
                    </table>
                </td></tr>
            </table>";

        return str_replace( 
            array('{pbc_match_status}', '{pbc_raw_data}', '{pbc_pcn}', '{pbc_owners}', '{pbc_search_url}'), 
            array("<span style='color:$color; font-weight:bold;'>$status</span>", $html_summary, $pcn, $owners_display, "https://pbcpao.gov/MasterSearch/SearchResults"), 
            $text 
        );
    }
}
