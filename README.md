# KeszlerShippingContextPreset

**KeszlerShippingContextPreset** is an open source Shopware 6 plugin under the MIT license for extending the functionality of the cart and product feeds. The main feature is letting the customer calculate their shipping cost prior to registration by selecting a country and specifying a zipcode. This feature is then also reflected in a custom twig extension that can be applied to product feeds, based on a preset country & zipcode.

## Why This Plugin Exists
If you rely on somewhat detailed shipping cost calculations, a customer might be deterred by not seeing their actual shipping costs up front, as they would have to login or register as a guest or customer first. They could also be deterred by the costs varying from what they saw before they registered. To avoid this confusion and making it less complicated for a potential customer, this plugin allows them to simulate an address: Users can select a country (built from the sales channel active countries) and input a 4-5 digit zipcode, which is then used to built a new context to run the new calculations, as if the user were logged in.


Without any context (either given in a previous calculation or by the user being logged in), the offcanvas cart adds "(geschätzt)" to the shipping costs, which links to the main cart:

<img width="394" height="808" alt="offcanvas" src="https://github.com/user-attachments/assets/1c7230e7-de7f-4cec-bcdc-250ceb544bd2" />


After a user has entered a zipcode (and maybe changed the country), the shipping address context stays applied and calculates the actual shipping cost, noticable by the zipcode now always showing in the summary:

<img width="1380" height="702" alt="cart" src="https://github.com/user-attachments/assets/962211c9-7451-4efc-a7d2-6dab4e2a2230" />

## Disclaimer
This plugin was originally created for internal use only, so it might be considered an oddly specific use case. However, if you rely on very detailed shipping cost calculations (such as zipcode & weight dependent freight forwarding), this plugin might be for you. 
Also, Please keep in mind that we are not in the plugin business, nor are we Shopware professionals. However, we'll try to keep this documented as well as possible, so if you decide to use our plugin as is, you should be able to. Maybe someone finds it useful or use it as a basis for customization.

## Known issues/quirks
- We've overwritten the shipping method selection in offcanvas-cart-summary.html.twig, since the plugin pretty much relies on the active rules making this determination for us
- There is no proper translation included

## About this release
We hope to give back to the open source community and will make more code available in the future. The recent Shopware TOS changes on 2025-03-24 (see https://forum.shopware.com/t/q-a-zur-fair-usage-policy-und-geaenderten-agb-vom-24-03-2025/106859), especially the way they redefine 'fair usage'... we're not really big fans, to put it lightly.

## License
This plugin is licensed under the [MIT License](./LICENSE).
