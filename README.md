# Initial setup 
https://devdocs.magento.com/cloud/before/before-setup-env-install.html
https://devdocs.magento.com/guides/v2.3/install-gde/install/sample-data.html
https://devdocs.magento.com/guides/v2.4/config-guide/cli/config-cli-subcommands-cache.html


# Installation

https://devdocs.magento.com/guides/v2.3/install-gde/composer.html
https://devdocs.magento.com/guides/v2.3/install-gde/system-requirements.html
https://devdocs.magento.com/guides/v2.4/install-gde/prereq/nginx.html

After downloading latest version of composer since magento 2.3.5 does not work with composer 2 we need to run the following command. 

sudo composer self-update --1

```
php7.2-bcmath 
php7.2-ctype

sudo composer create-project --repository-url=https://repo.magento.com/ magento/project-community-edition=2.3.5 nimbbl_kit_magento

bin/magento setup:install \
--base-url=http://uatmagentoshop.nimbbl.tech \
--db-host=nimbbl_db_uat \
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


bin/magento sampledata:deploy



```

# Setting the base url after installation.
sudo php bin/magento setup:store-config:set --base-url="http://uatmagentoshop.nimbbl.tech/" 
sudo php bin/magento setup:store-config:set --base-url-secure="https://uatmagentoshop.nimbbl.tech/" 
sudo php bin/magento setup:store-config:set --use-secure=1
sudo php bin/magento setup:store-config:set --use-secure-admin=1
sudo php bin/magento cache:flush

https://blog.netgloo.com/2016/05/13/magento-2-change-base-url-using-the-command-line/
https://uatmagentoshop.nimbbl.tech/admin_1c3xzg


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
sudo bin/magento setup:upgrade; sudo bin/magento cache:clean
```

(https://devdocs.magento.com/guides/v2.4/config-guide/cli/config-cli-subcommands-cron.html#create-or-remove-the-magento-crontab)
bin/magento cron:install

# Magento 2 add new payment method

https://devdocs.magento.com/guides/v2.4/howdoi/checkout/checkout_payment.html
https://www.mageplaza.com/devdocs/magento-2-create-payment-method/

# Razorpay test credentials

rzp_test_qr1cOGw8NE152R
8VtyMiuYrkkOdyfwcdxZ3MOq
