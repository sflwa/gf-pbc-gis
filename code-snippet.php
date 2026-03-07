<?php
add_action( 'admin_init', 'sflwa_pbc_directional_standardizer' );

function sflwa_pbc_directional_standardizer() {
    if ( ! isset( $_GET['gf-pbc-gis'] ) || ! isset( $_GET['addressupdate'] ) ) {
        return;
    }

    $target_form_id     = 1;    
    $legacy_address_id  = '4.1'; 
    $house_number_field = '8';   
    $street_name_field  = '9';  

    echo "<h3>Starting Directional & Capitalized Standardization for Form $target_form_id...</h3>";

    $entries = GFAPI::get_entries( $target_form_id, array( 'status' => 'active' ), null, array( 'page_size' => 400 ) );

    if ( is_wp_error( $entries ) || empty( $entries ) ) {
        die( "No entries found." );
    }

    $suffix_map = array(
        'DRIVE' => 'Dr', 'DR' => 'Dr',
        'STREET' => 'St', 'ST' => 'St',
        'AVENUE' => 'Ave', 'AVE' => 'Ave',
        'LANE' => 'Ln', 'LN' => 'Ln',
        'ROAD' => 'Rd', 'RD' => 'Rd',
        'COURT' => 'Ct', 'CT' => 'Ct',
        'BOULEVARD' => 'Blvd', 'BLVD' => 'Blvd',
        'WAY' => 'Way', 'CIRCLE' => 'Cir', 'CIR' => 'Cir'
    );

    // List of directional abbreviations to keep in ALL CAPS
    $directionals = array( 'NE', 'NW', 'SE', 'SW', 'N', 'S', 'E', 'W' );

    foreach ( $entries as $entry ) {
        $raw_address = trim( rgar( $entry, $legacy_address_id ) );
        if ( empty( $raw_address ) ) continue;

        // Improved Regex: capture leading digits even if there's weird spacing
        if ( preg_match( '/^(\d+)\s+(.*)$/', $raw_address, $matches ) ) {
            $num = $matches[1];
            $remainder = trim( $matches[2] );
            
            $words = preg_split( '/[\s,]+/', $remainder );
            $temp_street_parts = array();

            foreach ( $words as $word ) {
                $word = trim( $word );
                if ( empty( $word ) ) continue;

                $upper_word = strtoupper( $word );

                if ( array_key_exists( $upper_word, $suffix_map ) ) {
                    $temp_street_parts[] = $suffix_map[$upper_word];
                    break; // Cut off city/state data after the suffix
                } elseif ( in_array( $upper_word, $directionals ) ) {
                    $temp_street_parts[] = $upper_word; // Keep NE/NW in All-Caps
                } else {
                    $temp_street_parts[] = ucwords( strtolower( $word ) ); // Burgess/Edisto
                }
            }

            $final_street = implode( ' ', $temp_street_parts );

            echo "Entry #{$entry['id']}: '$raw_address' -> [Num: $num] [Street: $final_street] ";

            GFAPI::update_entry_field( $entry['id'], $house_number_field, $num );
            GFAPI::update_entry_field( $entry['id'], $street_name_field, $final_street );
            gform_update_meta( $entry['id'], 'pbc_status', '' );
            
            echo "<span style='color:green;'>UPDATED</span><br>";
        } else {
            echo "Entry #{$entry['id']}: <span style='color:red;'>SKIPPED (No leading number found in '$raw_address')</span><br>";
        }
    }
    echo "<h3>Standardization Complete.</h3>";
    exit;
}
php?>
