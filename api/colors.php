<?php
declare(strict_types=1);

// 商品ごとのカラー。key => 表示名と画像ファイル名。
// tools/build-catalog.mjs が catalog/products.tsv から生成する。直接書き換えない。
return [
    'off-box' => [
        'white' => ['label' => 'ホワイト', 'image' => 'off-box-centered.png'],
        'black' => ['label' => 'ブラック', 'image' => 'off-box-black.png'],
        'pink' => ['label' => 'ピンク', 'image' => 'off-box-pink.png'],
        'blue' => ['label' => 'スカイブルー', 'image' => 'off-box-blue.png'],
        'purple' => ['label' => 'パープル', 'image' => 'off-box-purple.png'],
    ],
    'lock-case-sos' => [
        'cream' => ['label' => 'オフホワイト', 'image' => 'lock-case-sos-cream.jpg'],
        'blue' => ['label' => 'ブルー', 'image' => 'lock-case-sos-blue.jpg'],
        'pink' => ['label' => 'ピンク', 'image' => 'lock-case-sos-pink.jpg'],
    ],
];
