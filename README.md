# Initial setup 
https://devdocs.magento.com/cloud/before/before-setup-env-install.html
https://devdocs.magento.com/guides/v2.3/install-gde/install/sample-data.html
https://devdocs.magento.com/guides/v2.4/config-guide/cli/config-cli-subcommands-cache.html

```
bin/magento setup:install \
--base-url=http://magento2ce.local \
--db-host=localhost \
--db-name=magento \
--db-user=magento \
--db-password=magento \
--admin-firstname=admin \
--admin-lastname=admin \
--admin-email=admin@admin.com \
--admin-user=admin \
--admin-password=admin123 \
--language=en_US \
--currency=USD \
--timezone=America/Chicago \
--use-rewrites=1
```

During the above command installation, if the mariadb or mysql version is not as per the one required by Magento, then you will get an error. The below link has a potential solution to this.

(https://stackoverflow.com/questions/64752612/magento-2-error-current-version-of-rdbms-is-not-supported-used-version-10-1-37)

If everything goes well, then you will see the below. 

```
[SUCCESS]: Magento installation complete.
[SUCCESS]: Magento Admin URI: /admin_uyzn1q
Nothing to import.
```


#  Some useful commands

```
bin/magento setup:di:compile
bin/magento info:adminuri 
bin/magento module:disable Magento_TwoFactorAuth
bin/magento sampledata:deploy
bin/magento setup:upgrade
bin/magento cache:status
bin/magento cache:flush


bin/magento setup:upgrade --keep-generated; bin/magento setup:static-content:deploy; bin/magento cache:clean
```

(https://devdocs.magento.com/guides/v2.4/config-guide/cli/config-cli-subcommands-cron.html#create-or-remove-the-magento-crontab)
bin/magento cron:install

# Magento 2 add new payment method

https://devdocs.magento.com/guides/v2.4/howdoi/checkout/checkout_payment.html
https://www.mageplaza.com/devdocs/magento-2-create-payment-method/
