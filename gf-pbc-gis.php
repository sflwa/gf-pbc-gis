<?php
/**
 * Plugin Name: SFLWA PBC Property Appraiser Bridge
 * Version: 1.2.9
 * Author: Philip Levine
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

    protected $_version = '1.2.9';
    protected $_min_gravityforms_version = '2.5';
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
    }

    public function add_merge_tags( $merge_tags, $form_id, $fields ) {
        $merge_tags[] = array( 'group' => 'pbc_verification', 'label' => 'PBC: Full Result Box', 'tag' => '{pbc_raw_data}' );
        $merge_tags[] = array( 'group' => 'pbc_verification', 'label' => 'PBC: Match Status', 'tag' => '{pbc_match_status}' );
        $merge_tags[] = array( 'group' => 'pbc_verification', 'label' => 'PBC: Master Search Link', 'tag' => '{pbc_search_url}' );
        $merge_tags[] = array( 'group' => 'pbc_verification', 'label' => 'PBC: Parcel Number (PCN)', 'tag' => '{pbc_pcn}' );
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
        $form  = $args['form'];
        $entry = $args['entry'];
        $action = $this->_slug . '_process_lookup';

        if ( rgpost( 'action' ) == $action ) {
            check_admin_referer( 'gforms_save_entry', 'gforms_save_entry' );
            $this->get_pbc_data_for_entry( $form, $entry, true ); 
            $entry = GFAPI::get_entry( $entry['id'] ); 
        }

        $status = gform_get_meta( $entry['id'], 'pbc_status' );
        $owner  = gform_get_meta( $entry['id'], 'pbc_owner' );
        $pcn    = gform_get_meta( $entry['id'], 'pbc_pcn' );
        
        $html = '<div style="line-height:1.6;">';
        $color = ( $status === 'Matched' ) ? '#27ae60' : '#e74c3c';
        $html .= '<strong>Status:</strong> <span style="color:'.$color.'; font-weight:bold;">' . esc_html( $status ?: 'Not Run' ) . '</span><br>';
        
        if ( $pcn ) {
            $html .= '<strong>Owner:</strong> ' . esc_html( $owner ) . '<br>';
            $html .= '<strong>PCN:</strong> ' . esc_html( $pcn ) . '<br>';
        }

        $html .= '<div style="margin-top:10px;">';
        $html .= sprintf( '<input type="submit" value="Re-Run Lookup" class="button" onclick="jQuery(\'#action\').val(\'%s\');" style="width:100%%; margin-bottom:5px;" />', $action );
        
        if ( $pcn ) {
            $html .= sprintf( '<a href="https://pbcpao.gov/Property/Details?parcelId=%s" target="_blank" class="button button-primary" style="display:block; text-align:center;">View Record</a>', $pcn );
        }
        
        $html .= '</div></div>';
        echo $html;
    }

    public function form_settings_fields( $form ) {
        return array(
            array(
                'title'  => 'PBC API Settings',
                'fields' => array(
                    array('label' => 'Enable PBC Verification', 'type' => 'toggle', 'name' => 'enabled'),
                    array('label' => 'Condo Mode (Uses Site Address String)', 'type' => 'toggle', 'name' => 'condo_mode'),
                    array(
                        'label'   => 'Static Base Address',
                        'type'    => 'text',
                        'name'    => 'static_address',
                        'placeholder' => 'Example: 2600 NE 1st Ln',
                    ),
                    array(
                        'label'      => 'Field Mapping',
                        'type'       => 'field_map',
                        'name'       => 'field_mapping',
                        'field_type' => array( 'text', 'address', 'select', 'name' ),
                        'field_map'  => array(
                            array( 'name' => 'unit_number',   'label' => 'Unit Number Field' ),
                            array( 'name' => 'resident_name', 'label' => 'Resident Name Field' ),
                            array( 'name' => 'base_addr_field', 'label' => 'Full Address Field (Alternative to Static)' ),
                        ),
                    ),
                    array(
                        'type'     => 'html',
                        'name'     => 'merge_tag_reference',
                        'callback' => array( $this, 'settings_merge_tags' ),
                    ),
                ),
            ),
        );
    }

    public function after_submission( $form, $entry ) {
        $this->get_pbc_data_for_entry( $form, $entry );
    }

    public function get_pbc_data_for_entry( $form, $entry, $force = false ) {
        $settings = $this->get_form_settings( $form );
        if ( empty( $settings['enabled'] ) ) return false;
        if ( ! $force && gform_get_meta( $entry['id'], 'pbc_pcn' ) ) return true;

        $unit = $this->get_safe_value( $entry, 'unit_number', $settings );
        $name = $this->get_safe_value( $entry, 'resident_name', $settings );
        $base = ! empty($settings['static_address']) ? $settings['static_address'] : $this->get_safe_value($entry, 'base_addr_field', $settings);
        
        if ( empty($base) ) return false;

        $api_base = "https://services1.arcgis.com/ZWOoUZbtaYePLlPw/arcgis/rest/services/Property_Information_Table/FeatureServer/0/query";

        if ( ! empty($settings['condo_mode']) ) {
            // Condo Mode uses verified SITE_ADDR_STR field
            $full_string = strtoupper(trim($base . " " . $unit));
            $where = "SITE_ADDR_STR LIKE '" . esc_sql($full_string) . "%'";
        } else {
            // Standard Split Logic
            $addr_parts = explode(' ', trim($base), 2);
            $house_num = $addr_parts[0];
            $street_name = isset($addr_parts[1]) ? strtoupper($addr_parts[1]) : '';
            $where = "STREET_NUMBER = " . intval($house_num) . " AND STREET_NAME LIKE '" . esc_sql($street_name) . "%'";
        }

        $api_url = add_query_arg( array( 'where' => $where, 'outFields' => 'PARCEL_NUMBER,OWNER_NAME1', 'f' => 'json' ), $api_base );
        $res = wp_remote_get( $api_url, array('timeout' => 25, 'user-agent' => 'Mozilla/5.0') );
        $data = json_decode( wp_remote_retrieve_body($res), true );

        if ( ! empty($data['features']) ) {
            $attr = $data['features'][0]['attributes'];
            $match = $this->fuzzy_match( $name, $attr['OWNER_NAME1'] );
            gform_update_meta( $entry['id'], 'pbc_status', $match ? 'Matched' : 'Mismatch' );
            gform_update_meta( $entry['id'], 'pbc_owner', $attr['OWNER_NAME1'] );
            gform_update_meta( $entry['id'], 'pbc_pcn', $attr['PARCEL_NUMBER'] );
        } else {
            gform_update_meta( $entry['id'], 'pbc_status', 'Not Found' );
        }
        
        GFAPI::add_note( $entry['id'], 0, 'PBC Bridge', "Query: $where\nAPI: $api_url" );
        return true;
    }

    private function get_safe_value( $entry, $key, $settings ) {
        $field_id = rgar( $settings, "field_mapping_{$key}" );
        if ( empty( $field_id ) ) {
            $mapping = rgar( $settings, 'field_mapping' );
            if ( is_array( $mapping ) ) {
                foreach ( $mapping as $m ) {
                    if ( rgar( $m, 'name' ) === $key ) { $field_id = rgar( $m, 'value' ); break; }
                }
            }
        }
        return rgar( $entry, (string) $field_id );
    }

    private function fuzzy_match( $input, $official ) {
        $input = strtolower(trim($input)); $official = strtolower(trim($official));
        if (empty($input) || empty($official)) return false;
        $input_parts = array_filter(explode(' ', $input));
        $official_parts = array_filter(explode(' ', $official));
        $matches = 0;
        foreach ($input_parts as $part) {
            if (strlen($part) > 2 && in_array($part, $official_parts)) $matches++;
        }
        return ($matches >= 1);
    }

    public function replace_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
        if ( ! strpbrk($text, '{}') ) return $text;
        $this->get_pbc_data_for_entry( $form, $entry );
        $status = gform_get_meta( $entry['id'], 'pbc_status' ) ?: 'Not Verified';
        $owner  = gform_get_meta( $entry['id'], 'pbc_owner' ) ?: 'N/A';
        $pcn    = gform_get_meta( $entry['id'], 'pbc_pcn' ) ?: 'N/A';
        $color  = ($status === 'Matched') ? '#27ae60' : '#e74c3c';
        
        $html_summary = "
            <table width='100%' border='0' cellpadding='0' cellspacing='0' style='background-color: #f4f7f9; border-radius: 4px; font-family: sans-serif; margin-bottom: 20px;'>
                <tr><td style='padding: 15px; border-bottom: 1px solid #e1e8ed;'>
                    <span style='font-size: 11px; font-weight: bold; text-transform: uppercase; color: #5e6d77; display: block; margin-bottom: 5px;'>PBC Property Verification</span>
                    <span style='font-size: 18px; font-weight: bold; color: $color;'>$status</span>
                </td></tr>
                <tr><td style='padding: 15px; background-color: #ffffff;'>
                    <table width='100%' border='0'>
                        <tr><td style='padding-bottom: 10px; border-bottom: 1px solid #f0f3f5;'>
                            <span style='font-size: 11px; font-weight: bold; color: #919da5;'>OFFICIAL OWNER</span><br>
                            <span style='font-size: 14px; color: #2c3e50;'>$owner</span>
                        </td></tr>
                        <tr><td style='padding-top: 10px;'>
                            <span style='font-size: 11px; font-weight: bold; color: #919da5;'>PARCEL CONTROL NUMBER (PCN)</span><br>
                            <span style='font-size: 14px; color: #2c3e50;'>$pcn</span>
                        </td></tr>
                    </table>
                </td></tr>
            </table>";

        $text = str_replace( 
            array('{pbc_match_status}', '{pbc_raw_data}', '{pbc_pcn}'), 
            array("<span style='color:$color; font-weight:bold;'>$status</span>", $html_summary, $pcn), 
            $text 
        );
        return $text;
    }
}
