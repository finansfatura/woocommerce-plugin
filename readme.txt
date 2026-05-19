=== Finansfatura for WooCommerce ===
Contributors: finansfatura
Tags: woocommerce, e-fatura, e-arsiv, invoice, turkey
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce sipariş ve iadeleriniz için Finansfatura üzerinden otomatik e-Arşiv / e-Fatura kesin.

== Description ==

Finansfatura entegrasyon eklentisi WooCommerce siparişleriniz tamamlandığında otomatik olarak e-Arşiv veya e-Fatura keser, iade oluştuğunda IADE faturası gönderir, ve sipariş ekranına manuel "Fatura Kes" butonu ekler.

= Öne çıkanlar =
* Bireysel / Kurumsal alıcı seçimi + TCKN/VKN + vergi dairesi alanları
* Sipariş tamamlandığında otomatik fatura
* Refund'da otomatik IADE faturası
* Manuel "Fatura Kes" butonu
* HPOS (Custom Order Tables) tam uyumlu
* Action Scheduler ile retry
* Deterministic Idempotency-Key — tekrar denemelerde mükerrer fatura kesilmez

== Installation ==

1. WooCommerce 8.0+ ve PHP 7.4+ kurulu olmalı.
2. Eklentiyi WordPress → Plugins → Add New üzerinden yükleyin ve aktive edin.
3. WooCommerce → Finansfatura ekranından API anahtarı ve satıcı bilgilerini girin.
4. Finansfatura panelinde Ayarlar → API Anahtarları ekranından yeni bir anahtar üretin ve buraya yapıştırın.

== Frequently Asked Questions ==

= API anahtarımı nasıl alırım? =
Finansfatura panelinde "Ayarlar → API Anahtarları" sayfasından "Yeni anahtar" butonu ile üretin. Anahtar yalnızca üretim anında gösterilir.

= Aynı sipariş için iki kez "Fatura Kes" basarsam ne olur? =
Idempotency-Key deterministic olduğu için Finansfatura aynı faturayı döner; mükerrer satır oluşmaz.

== Changelog ==

= 0.1.0 =
* İlk sürüm.
