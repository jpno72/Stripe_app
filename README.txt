BMA Analytics database install scripts

Recommended sequence
--------------------
1. In cPanel, create the empty MariaDB database and database user first.
2. Assign the privileges required by the BMA installer/migrations.
3. Keep the project outside the public web root and serve only public/.
4. Make scripts executable:

   chmod +x 01_prepare_bma_config.sh 02_install_bma_database.sh 03_verify_bma_database.sh

5. From the project root:

   ./01_prepare_bma_config.sh

6. Edit:

   nano ~/bma-private/config.php

7. Install/apply migrations:

   ./02_install_bma_database.sh

8. Verify:

   ./03_verify_bma_database.sh

Noninteractive apply after manual review:
   BMA_INSTALL_YES=1 ./02_install_bma_database.sh

Important
---------
- Never put config.php inside public_html.
- Never commit config.php to Git.
- Do not separately import sql/schema.sql if you use bin/install.php on the same database.
- sql/schema.sql is the consolidated empty-database fallback if the migration runner cannot be used.
- Do not run disposable schema tests against production.
