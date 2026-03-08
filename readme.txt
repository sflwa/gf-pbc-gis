=== SFLWA Gravity Forms PBC Property Appraiser Lookup ===
Contributors: sflwa
Tags: gravity forms, pbc, property appraiser, hoa, condo, bulk actions
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.5.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verify property ownership in Palm Beach County (PBC) directly within Gravity Forms. Includes advanced filtering and bulk lookup tools for HOA and Condo managers.

== Description ==

The SFLWA Gravity Forms PBC Property Appraiser Bridge allows HOA and Condo Association managers to verify property ownership in real-time. By connecting to the Palm Beach County Property Appraiser's ArcGIS data, the plugin cross-references resident names against official county records.

Key features include:
* **Bulk Lookups**: Process hundreds of entries at once using the "Trigger PBC Lookup" bulk action in the Entry List.
* **Match Status Filtering**: Quickly filter your entry list to show only "Matched", "Mismatch", or "Not Found" records.
* **Condo Mode**: Optimized for multi-unit buildings where the unit number is appended to the site address string.
* **Static Address Support**: Hard-code a community address so users only need to enter their unit number.
* **Dual Owner Matching**: Automatically checks both OWNER_NAME1 and OWNER_NAME2 fields for a match.
* **Name Reversal Logic**: Intelligently matches "First Last" against the database's "LAST FIRST" format.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to your Gravity Form 'Settings' and select 'PBC Verification'.
4. Enable the bridge and map your Name, Unit, and Address fields.

== Frequently Asked Questions ==

= How do I use Bulk Actions? =
In the Gravity Forms Entry List, select the entries you wish to verify, open the "Bulk Actions" dropdown, and select "Trigger PBC Lookup".

= Can I filter by Mismatches? =
Yes. Click "Add Filter" on the Entry List page and select "Match Status" is "Mismatch".

== Changelog ==

= 1.4.0 =
* Added "Trigger PBC Lookup" Bulk Action to Entry List.
* Added "Match Status" search filters to Entry List.
* Optimized metadata registration for better UI visibility.

= 1.3.5 =
* Fixed Match Status column visibility in the Entry List UI.
* Updated plugin headers and documentation.

= 1.3.2 =
* Improved fuzzy matching for reversed first/last names.

= 1.3.0 =
* Added support for secondary owner (OWNER_NAME2) lookups.

= 1.2.9 =
* Implemented Condo Mode using SITE_ADDR_STR for unit-specific queries.
