#!/usr/bin/env python3
import sys

import mysql.connector

# Your Facebook connection data
facebook_data = {
    "wc_facebook_access_token": "EAAGvQJc4NAQBPNXS1f4ZAbSQg9qVGzIi3IIfaijq3B4N7vn3jtWMDQNunV3vlFivtPpZAvuzMi6BLxgGnxVZAgr8ZBBOJI5rORLEOZAs4VG5vsFtPOs9iFdeCGl8ScefkkPB6GJfsMS8L1BOUu45eZCKyaaM0GMNfoBo5IwkhJagaZBU4SRBC6DQ22MWRn4zyTB",
    "wc_facebook_merchant_access_token": "EAAGvQJc4NAQBPNXS1f4ZAbSQg9qVGzIi3IIfaijq3B4N7vn3jtWMDQNunV3vlFivtPpZAvuzMi6BLxgGnxVZAgr8ZBBOJI5rORLEOZAs4VG5vsFtPOs9iFdeCGl8ScefkkPB6GJfsMS8L1BOUu45eZCKyaaM0GMNfoBo5IwkhJagaZBU4SRBC6DQ22MWRn4zyTB",
    "wc_facebook_business_manager_id": "746706748528132",
    "wc_facebook_commerce_merchant_settings_id": "18802401906550",
    "wc_facebook_commerce_partner_integration_id": "51502401300989",
    "wc_facebook_external_business_id": "mywoocstore-68a30e3940b4c",
    "wc_facebook_has_authorized_pages_read_engagement": "yes",
    "wc_facebook_has_connected_fbe_2": "yes",
    "wc_facebook_installed_features": 'a:5:{i:0;a:4:{s:19:"feature_instance_id";s:14:"52202399491730";s:12:"feature_type";s:15:"external_client";s:16:"connected_assets";a:1:{s:19:"business_manager_id";s:15:"746706748528132";}s:15:"additional_info";a:1:{s:15:"onsite_eligible";b:0;}}i:1;a:4:{s:19:"feature_instance_id";s:14:"47902399602719";s:12:"feature_type";s:7:"fb_shop";s:16:"connected_assets";a:4:{s:19:"business_manager_id";s:15:"746706748528132";s:10:"catalog_id";s:14:"33702540147099";s:29:"commerce_merchant_settings_id";s:14:"18802401906550";s:7:"page_id";s:14:"31602441568478";}s:15:"additional_info";a:1:{s:15:"onsite_eligible";b:0;}}i:2;a:4:{s:19:"feature_instance_id";s:14:"40402405406434";s:12:"feature_type";s:5:"pixel";s:16:"connected_assets";a:4:{s:13:"ad_account_id";s:14:"26602407919750";s:19:"business_manager_id";s:15:"746706748528132";s:7:"page_id";s:14:"31602441568478";s:8:"pixel_id";s:14:"21602405281339";}s:15:"additional_info";a:2:{s:15:"onsite_eligible";b:0;s:31:"pixel_is_consolidated_container";b:0;}}i:3;a:4:{s:19:"feature_instance_id";s:14:"38802432974820";s:12:"feature_type";s:3:"ads";s:16:"connected_assets";a:5:{s:13:"ad_account_id";s:14:"26602407919750";s:19:"business_manager_id";s:15:"746706748528132";s:10:"catalog_id";s:14:"33702540147099";s:7:"page_id";s:14:"31602441568478";s:8:"pixel_id";s:14:"21602405281339";}s:15:"additional_info";a:2:{s:15:"onsite_eligible";b:0;s:31:"pixel_is_consolidated_container";b:0;}}i:4;a:4:{s:19:"feature_instance_id";s:14:"21602405281703";s:12:"feature_type";s:7:"catalog";s:16:"connected_assets";a:5:{s:13:"ad_account_id";s:14:"26602407919750";s:19:"business_manager_id";s:15:"746706748528132";s:10:"catalog_id";s:14:"33702540147099";s:7:"page_id";s:14:"31602441568478";s:8:"pixel_id";s:14:"21602405281339";}s:15:"additional_info";a:2:{s:15:"onsite_eligible";b:0;s:31:"pixel_is_consolidated_container";b:0;}}}',
    "wc_facebook_pixel_id": "21602405281339",
    "wc_facebook_product_catalog_id": "33702540147099",
    "wc_facebook_ad_account_id": "26602407919750",  # Extracted from installed_features
}


def inject_data():
    try:
        # Connect to database (adjust credentials as needed)
        conn = mysql.connector.connect(
            host="localhost",
            user="root",
            password=input("Enter MySQL password: "),
            database="local",  # Your WordPress database name
        )
        cursor = conn.cursor()

        print("Injecting Facebook connection data...")

        for option_name, option_value in facebook_data.items():
            # Check if option exists
            cursor.execute(
                "SELECT option_id FROM wp_options WHERE option_name = %s",
                (option_name,),
            )
            result = cursor.fetchone()

            if result:
                # Update existing
                cursor.execute(
                    "UPDATE wp_options SET option_value = %s WHERE option_name = %s",
                    (option_value, option_name),
                )
                print(f"✅ Updated {option_name}")
            else:
                # Insert new
                cursor.execute(
                    "INSERT INTO wp_options (option_name, option_value) VALUES (%s, %s)",
                    (option_name, option_value),
                )
                print(f"✅ Inserted {option_name}")

        conn.commit()
        print("\n🎉 All Facebook data injected successfully!")

    except mysql.connector.Error as err:
        print(f"❌ Database error: {err}")
    finally:
        if conn.is_connected():
            cursor.close()
            conn.close()


if __name__ == "__main__":
    inject_data()
