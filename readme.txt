=== Finansfatura for WooCommerce ===
Contributors: finansfatura
Tags: woocommerce, e-fatura, e-arsiv, invoice, turkey
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce siparişlerinizi önce Finansfatura satışına çevirir, ardından e-Arşiv / e-Fatura keser.

== Description ==

Eklenti siparişi iki adımda işler:

1. **Satış** — ödeme alındığında sipariş Finansfatura'ya satış olarak aktarılır. Ciroya ve stoğa oradan girer; fatura kesilemese bile satış kaybolmaz. Fatura alıcısı (unvan/ad soyad, VKN/TCKN, vergi dairesi, adres, e-posta, telefon) satışın üzerine yazılır — ayrıca cari kartı açılmaz.
2. **Fatura** — belge o satışın üzerine kesilir. Sipariş "tamamlandı" olunca otomatik, ya da sipariş ekranındaki **Fatura Kes** düğmesiyle. Düğme de adımı atlamaz: satış yoksa önce onu oluşturur, sonra faturayı keser.

Belge her zaman e-Arşiv olarak gönderilir; alıcının VKN/TCKN'si GİB'de e-Fatura mükellefi çıkarsa sunucu belgeyi otomatik e-Faturaya yükseltir — müşteriye checkout'ta sorulmaz.

= Öne çıkanlar =
* Bireysel / Kurumsal alıcı seçimi + TCKN/VKN + vergi dairesi alanları; hepsi satışın fatura alıcısına yazılır
* Ödeme alınınca otomatik satış aktarımı
* Sipariş tamamlanınca otomatik fatura, iadede otomatik IADE faturası
* Manuel "Fatura Kes", "Satışı aktar" ve "Durumu yenile" düğmeleri
* Kargo ve ek ücret satırları da faturaya girer
* API anahtarı ya da OAuth 2.0 (PKCE) ile bağlantı
* HPOS (Custom Order Tables) tam uyumlu
* Deterministic Idempotency-Key + yalnızca yeniden denenebilir hatalarda Action Scheduler retry

Resmî PHP kütüphanesi (`finansfatura/finansfatura`) kullanılır: uç adresleri, gövde biçimi ve hata sınıfları oradan gelir.

== Installation ==

1. WooCommerce 8.0+ ve PHP 8.1+ kurulu olmalı.
2. Eklentiyi WordPress → Plugins → Add New → Upload üzerinden yükleyin ve aktive edin.
3. WooCommerce → Finansfatura ekranından API anahtarınızı girin.
4. Anahtarı Finansfatura panelinde **Ayarlar → API Anahtarları** sayfasından üretin; açık hâli yalnızca üretim anında gösterilir.

Depodan (git clone) kurduysanız eklenti dizininde `composer install --no-dev` çalıştırın — indirilen zip'te bağımlılıklar hazır gelir.

== Frequently Asked Questions ==

= Fatura kesilmezse satış da kaybolur mu? =
Hayır. Satış ayrı bir adım; fatura hata alsa da satış Finansfatura'da durur, faturayı sonra panelden ya da "Fatura Kes" düğmesinden kesebilirsiniz.

= Aynı sipariş için iki kez "Fatura Kes" basarsam ne olur? =
Idempotency-Key deterministic olduğu için Finansfatura aynı faturayı döner; mükerrer belge kesilmez, kontör iki kez düşmez. Satış tarafında da aynısı geçerlidir (`external_id`).

= Fatura numarasını neden hemen göremiyorum? =
Numarayı entegratör belge kesildikten sonra atar, kesme cevabında gelmez. Sipariş ekranındaki "Durumu yenile" düğmesi numarayı ve güncel durumu çeker.

= OAuth'u nasıl kullanırım? =
Ayarlar ekranındaki callback adresini partner@finansfatura.com adresine bildirip mağazanız için client_id / client_secret alın, ekrana girin ve "Finansfatura'ya bağlan" deyin. Kayıtlı callback'i olmayan mağazalar API anahtarıyla devam eder.

== Changelog ==

= 1.0.0 =
* Önce satış, sonra fatura: sipariş `POST /v1/integrations/orders` ile satışa çevriliyor, fatura o satışın `transaction_id`'sine bağlanıyor.
* Resmî `finansfatura/finansfatura` PHP kütüphanesine geçildi. Fatura gövdesi artık kütüphanenin `Payload` yapıcısıyla üretiliyor — `canonical` içindeki PascalCase alan adları eskiden snake_case gönderiliyordu ve sunucu tarafından sessizce yok sayılıyordu.
* OAuth 2.0 (authorization code + PKCE) desteği, alternatif kimlik doğrulama olarak.
* Kargo ve ek ücret satırları faturaya ekleniyor (eskiden fatura, müşterinin ödediğinden az kesiliyordu).
* "Satışı aktar" ve "Durumu yenile" düğmeleri; fatura numarası sipariş ekranında.
* Retry yalnızca API'nin yeniden denenebilir dediği hatalarda (429/5xx/bağlantı) çalışıyor.
* Checkout'taki "e-Fatura mükellefi" kutusu ve satıcı (issuer) ayarları kaldırıldı: ikisini de sunucu çözüyor.

= 0.1.0 =
* İlk sürüm.
