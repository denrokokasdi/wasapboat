# WA AI Chat

Plugin WordPress: chatbot AI WhatsApp + portal chat untuk Solarex MY.

- Sambung terus ke WhatsApp Cloud API (Meta)
- Jawab guna AI melalui ServerasAI (API serasi OpenAI)
- Portal **WA Chat** dalam admin WordPress: chat, label, status bayaran, nota
- Memory: 20 mesej terakhir + memori jangka panjang (fakta pelanggan) + order WooCommerce
- Hantar gambar produk, balas gaya manusia (mesej pendek, "typing...")

## Pasang

1. Muat turun `wa-ai-chat.zip` dari [Releases](../../releases/latest)
2. WordPress → Plugins → Add Plugin → Upload Plugin
3. Buka **WA Chat → Tetapan**

## Update

Plugin semak GitHub Releases setiap 6 jam. Bila ada versi baru, butang **Update now** muncul di page Plugins. Boleh juga tekan **Semak update sekarang** di Tetapan.

## Buat versi baru (untuk developer)

1. Naikkan `Version:` dalam `wa-ai-chat/wa-ai-chat.php`
2. Commit dan push ke `main`
3. Buat tag yang sama dengan versi, contoh `git tag v1.4.1 && git push origin v1.4.1`
4. GitHub Actions akan bina `wa-ai-chat.zip` dan terbitkan Release secara automatik

Tiada API key atau token dalam kod. Semua rahsia disimpan dalam database WordPress.
