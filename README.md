# MediaOrderFixer

Omeka S 管理画面で、アイテムのメディア並び順を「レビューしてから適用」できるモジュールです。

## 主な機能

- 識別子入力（テキスト貼り付け / テキストファイルアップロード）
- 並び替え方式の選択（`padded_first` / `plain_first` / `interleave`）
- 適用前レビュー（Before / After サンプル、除外理由表示）
- 対象アイテムの選択適用 / 一括適用
- 適用時に `media.position` を更新
- 適用時のプライマリーメディア更新
  - 並び替え後の**最初の公開メディア**を優先
  - 公開メディアがない場合は先頭メディアをフォールバック採用

## 要件

- Omeka S `^4.0.0`
- 管理権限（`global_admin` / `site_admin`）

## インストール

1. このリポジトリを `modules/MediaOrderFixer` に配置
2. Omeka S 管理画面の「モジュール」から `Media Order Fixer` をインストール

## 使い方

1. 管理画面メニューから **Media Order Fixer** を開く
2. アイテム識別子を入力（または `.txt/.csv` をアップロード）
3. 並び替え方式を選んで **Review candidates**
4. プレビューを確認し、対象行を選択
5. **Apply selected reorder** または **Apply all eligible items** を実行

## ルーティング

- `admin/media-order-fixer`
- `admin/media-order-fixer/apply`

## ライセンス

MIT License。詳細は [LICENSE](LICENSE) を参照してください。

## リポジトリ

- https://github.com/wakitosh/MediaOrderFixer
