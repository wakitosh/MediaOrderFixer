<?php

declare(strict_types=1);

namespace MediaOrderFixer\Controller\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Session\Container as SessionContainer;
use Laminas\View\Model\ViewModel;
use Omeka\Entity\Item;
use Omeka\Entity\Media;
use Omeka\Form\ConfirmForm;

/**
 * Admin controller to review and apply media reorder plans.
 */
class IndexController extends AbstractActionController {

  /**
   * Database connection.
   *
   * @var \Doctrine\DBAL\Connection
   */
  private Connection $connection;

  /**
   * Doctrine entity manager.
   *
   * @var \Doctrine\ORM\EntityManager
   */
  private EntityManager $entityManager;

  /**
   * Controller constructor.
   */
  public function __construct(Connection $connection, EntityManager $entityManager) {
    $this->connection = $connection;
    $this->entityManager = $entityManager;
  }

  /**
   * Render input/review page and build preview from submitted identifiers.
   */
  public function indexAction(): ViewModel {
    $session = $this->getSession();
    $this->cleanupSessionPlans($session);

    $strategyOptions = $this->strategyOptions();
    $strategy = (string) $this->params()->fromPost('strategy', 'padded_first');
    if (!isset($strategyOptions[$strategy])) {
      $strategy = 'padded_first';
    }

    $reviewPage = max(1, (int) $this->params()->fromQuery('review_page', 1));
    $reviewPerPage = min(200, max(10, (int) $this->params()->fromQuery('review_per_page', 50)));
    $previewToken = trim((string) $this->params()->fromQuery('preview_token', ''));
    $preferredSiteSlug = trim((string) $this->params()->fromQuery('site_slug', ''));

    $siteSlugs = [];
    $siteSlugRows = $this->connection->fetchFirstColumn("SELECT slug FROM site WHERE slug IS NOT NULL AND slug != '' ORDER BY slug ASC");
    foreach ($siteSlugRows as $slug) {
      $slug = trim((string) $slug);
      if ($slug !== '') {
        $siteSlugs[] = $slug;
      }
    }
    $siteSlugs = array_values(array_unique($siteSlugs));
    if ($preferredSiteSlug !== '' && !in_array($preferredSiteSlug, $siteSlugs, TRUE)) {
      $preferredSiteSlug = '';
    }

    $inputText = trim((string) $this->params()->fromPost('identifiers_text', ''));
    $preview = NULL;
    $confirmForm = $this->getForm(ConfirmForm::class);

    $request = $this->getRequest();
    $isPost = method_exists($request, 'isPost') ? $request->isPost() : FALSE;
    $mode = trim((string) $this->params()->fromPost('mode', ''));
    if ($isPost && $mode === 'preview') {
      $inputText = $this->mergeUploadText($inputText);
      $tokens = $this->parseIdentifiers($inputText);
      if (empty($tokens)) {
        $this->messenger()->addError($this->translate('No identifiers were provided.'));
      }
      else {
        try {
          $built = $this->buildPreview($tokens, $strategy);
          $previewToken = (string) $built['token'];
          $session->plans[$previewToken] = [
            'created' => time(),
            'strategy' => $strategy,
            'items' => $built['plans'],
            'preview' => [
              'rows' => $built['rows'],
              'resolved_count' => $built['resolved_count'],
              'unresolved' => $built['unresolved'],
              'ambiguous' => $built['ambiguous'],
            ],
            'identifiers_text' => $inputText,
          ];
          $reviewPage = 1;
        }
        catch (\Throwable $e) {
          $this->messenger()->addError($this->translate('Failed to build review preview: ') . $e->getMessage());
        }
      }
    }

    if ($previewToken !== '' && isset($session->plans[$previewToken])) {
      $payload = (array) ($session->plans[$previewToken]['preview'] ?? []);
      $allRows = is_array($payload['rows'] ?? NULL) ? $payload['rows'] : [];
      $totalRows = count($allRows);
      $pages = (int) max(1, ceil($totalRows / $reviewPerPage));
      $reviewPage = min($pages, $reviewPage);
      $offset = ($reviewPage - 1) * $reviewPerPage;
      $rows = array_slice($allRows, $offset, $reviewPerPage);
      $rows = $this->attachRowThumbs($rows, $preferredSiteSlug);

      $preview = [
        'token' => $previewToken,
        'rows' => $rows,
        'total_rows' => $totalRows,
        'page' => $reviewPage,
        'per_page' => $reviewPerPage,
        'pages' => $pages,
        'resolved_count' => (int) ($payload['resolved_count'] ?? 0),
        'unresolved' => is_array($payload['unresolved'] ?? NULL) ? $payload['unresolved'] : [],
        'ambiguous' => is_array($payload['ambiguous'] ?? NULL) ? $payload['ambiguous'] : [],
      ];

      if (!$isPost) {
        $savedStrategy = (string) ($session->plans[$previewToken]['strategy'] ?? '');
        if ($savedStrategy !== '' && isset($strategyOptions[$savedStrategy])) {
          $strategy = $savedStrategy;
        }
        $savedText = (string) ($session->plans[$previewToken]['identifiers_text'] ?? '');
        if ($savedText !== '') {
          $inputText = $savedText;
        }
      }
    }

    return new ViewModel([
      'strategy' => $strategy,
      'strategyOptions' => $strategyOptions,
      'identifiersText' => $inputText,
      'preview' => $preview,
      'siteSlug' => $preferredSiteSlug,
      'siteSlugs' => $siteSlugs,
      'confirmForm' => $confirmForm,
    ]);
  }

  /**
   * Apply selected reorder plans.
   */
  public function applyAction() {
    $request = $this->getRequest();
    $isPost = method_exists($request, 'isPost') ? $request->isPost() : FALSE;
    if (!$isPost) {
      return $this->redirect()->toRoute('admin/media-order-fixer');
    }

    $confirmForm = $this->getForm(ConfirmForm::class);
    $post = $this->params()->fromPost();
    $confirmForm->setData($post);
    if (!$confirmForm->isValid()) {
      $this->messenger()->addError($this->translate('Invalid CSRF token.'));
      return $this->redirect()->toRoute('admin/media-order-fixer');
    }

    $token = trim((string) ($post['preview_token'] ?? ''));
    $selectedItemIds = $post['selected_item_ids'] ?? [];
    if (is_string($selectedItemIds)) {
      $selectedItemIds = [$selectedItemIds];
    }
    $selectedItemIds = array_values(array_unique(array_map('intval', is_array($selectedItemIds) ? $selectedItemIds : [])));
    $applyAll = (int) ($post['apply_all'] ?? 0) === 1;

    $session = $this->getSession();
    $plans = (array) ($session->plans[$token]['items'] ?? []);
    if ($token === '' || empty($plans)) {
      $this->messenger()->addError($this->translate('Preview data was not found. Please run preview again.'));
      return $this->redirect()->toRoute('admin/media-order-fixer');
    }

    if ($applyAll) {
      $selectedItemIds = [];
      foreach ($plans as $planItemId => $plan) {
        if (!empty($plan['eligible']) && !empty($plan['changed'])) {
          $selectedItemIds[] = (int) $planItemId;
        }
      }
      $selectedItemIds = array_values(array_unique($selectedItemIds));
    }

    $updated = 0;
    $skipped = 0;
    foreach ($selectedItemIds as $itemId) {
      $itemId = (int) $itemId;
      $plan = $plans[$itemId] ?? NULL;
      if (!$plan || empty($plan['eligible'])) {
        $skipped++;
        continue;
      }
      $orderedIds = array_values(array_map('intval', (array) ($plan['ordered_ids'] ?? [])));
      if (empty($orderedIds)) {
        $skipped++;
        continue;
      }

      /** @var \Omeka\Entity\Item|null $item */
      $item = $this->entityManager->find(Item::class, $itemId);
      if (!$item) {
        $skipped++;
        continue;
      }

      $entities = [];
      foreach ($orderedIds as $mediaId) {
        /** @var \Omeka\Entity\Media|null $media */
        $media = $this->entityManager->find(Media::class, $mediaId);
        if ($media && $media->getItem() && (int) $media->getItem()->getId() === $itemId) {
          $entities[] = $media;
        }
      }

      if (count($entities) !== count($orderedIds)) {
        $skipped++;
        continue;
      }

      $position = 1;
      foreach ($entities as $media) {
        $media->setPosition($position++);
      }

      $primaryMedia = NULL;
      foreach ($entities as $media) {
        if (method_exists($media, 'isPublic') && $media->isPublic()) {
          $primaryMedia = $media;
          break;
        }
      }
      if (!$primaryMedia) {
        $primaryMedia = $entities[0] ?? NULL;
      }
      $item->setPrimaryMedia($primaryMedia);
      // This controller writes the entities directly instead of going through
      // the API, so nothing bumps the item timestamp the way
      // AbstractEntityAdapter::updateTimestamps() would. Do it here: consumers
      // that key off "modified" — notably the IIIF manifest cache, which would
      // otherwise keep serving the previous canvas order — cannot see a reorder
      // or a primary media change any other way.
      $item->setModified(new \DateTime('now'));
      $updated++;
    }

    $this->entityManager->flush();
    unset($session->plans[$token]);

    if ($updated > 0) {
      $this->messenger()->addSuccess(sprintf($this->translate('Updated %d item(s).'), $updated));
    }
    if ($skipped > 0) {
      $this->messenger()->addWarning(sprintf($this->translate('Skipped %d item(s).'), $skipped));
    }

    return $this->redirect()->toRoute('admin/media-order-fixer');
  }

  /**
   * Return reorder strategy options.
   */
  private function strategyOptions(): array {
    return [
      'padded_first' => $this->translate('A: zero-padded first (0001..), then non-padded (1..)'),
      'plain_first' => $this->translate('B: non-padded first (1..), then zero-padded (0001..)'),
      'interleave' => $this->translate('C: interleave by number (0001,1,0002,2,...)'),
    ];
  }

  /**
   * Return session container used for preview plans.
   */
  private function getSession(): SessionContainer {
    return new SessionContainer('MediaOrderFixer');
  }

  /**
   * Remove expired preview plans from session.
   */
  private function cleanupSessionPlans(SessionContainer $session): void {
    if (!isset($session->plans) || !is_array($session->plans)) {
      $session->plans = [];
      return;
    }
    $now = time();
    foreach ($session->plans as $token => $payload) {
      $created = (int) ($payload['created'] ?? 0);
      if ($created <= 0 || ($now - $created) > 86400) {
        unset($session->plans[$token]);
      }
    }
  }

  /**
   * Merge uploaded text file content into textarea input.
   */
  private function mergeUploadText(string $inputText): string {
    $files = $this->params()->fromFiles();
    $file = $files['identifiers_file'] ?? NULL;
    if (!is_array($file)) {
      return $inputText;
    }
    $tmp = trim((string) ($file['tmp_name'] ?? ''));
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($tmp === '' || $err !== UPLOAD_ERR_OK || !is_readable($tmp)) {
      return $inputText;
    }

    $content = @file_get_contents($tmp);
    if ($content === FALSE) {
      return $inputText;
    }
    $content = trim((string) $content);
    if ($content === '') {
      return $inputText;
    }

    if ($inputText === '') {
      return $content;
    }
    return $inputText . "\n" . $content;
  }

  /**
   * Parse identifiers from free text.
   */
  private function parseIdentifiers(string $text): array {
    $tokens = preg_split('/[\r\n,\t\s]+/u', $text) ?: [];
    $tokens = array_values(array_unique(array_filter(array_map(static function ($v) {
      return trim((string) $v);
    }, $tokens), static function ($v) {
      return $v !== '';
    })));
    return $tokens;
  }

  /**
   * Build preview rows and reorder plans for resolved items.
   */
  private function buildPreview(array $tokens, string $strategy): array {
    $resolved = [];
    $unresolved = [];
    $ambiguous = [];

    foreach ($tokens as $token) {
      $itemIds = $this->resolveItemIds($token);
      if (count($itemIds) === 1) {
        $resolved[(int) $itemIds[0]] = $token;
      }
      elseif (count($itemIds) > 1) {
        $ambiguous[] = ['token' => $token, 'item_ids' => $itemIds];
      }
      else {
        $unresolved[] = $token;
      }
    }

    $rows = [];
    $plans = [];
    foreach (array_keys($resolved) as $itemId) {
      $itemId = (int) $itemId;
      $title = (string) ($this->connection->fetchOne("SELECT COALESCE(title, '') FROM resource WHERE id = :id", ['id' => $itemId], ['id' => \PDO::PARAM_INT]) ?? '');
      if ($title === '') {
        $title = '[' . $this->translate('Untitled') . ']';
      }
      $medias = $this->connection->fetchAllAssociative(
        "SELECT m.id, m.position, m.source, COALESCE(r.title, '') AS title, COALESCE(r.is_public, 1) AS is_public
         FROM media m
         LEFT JOIN resource r ON r.id = m.id
         WHERE m.item_id = :item_id
         ORDER BY m.position ASC, m.id ASC",
        ['item_id' => $itemId],
        ['item_id' => \PDO::PARAM_INT]
      );

      $analysis = $this->analyzeItemMedia($medias, $strategy);
      $beforeSamples = array_slice($medias, 0, 4);
      $afterSamples = array_slice($analysis['sorted'], 0, 4);

      $hasPublicMedia = FALSE;
      $primaryPreviewMedia = NULL;
      foreach ($analysis['sorted'] as $sortedMedia) {
        $isPublic = (int) ($sortedMedia['is_public'] ?? 1) === 1;
        if ($isPublic && !$primaryPreviewMedia) {
          $primaryPreviewMedia = $sortedMedia;
        }
        if ($isPublic) {
          $hasPublicMedia = TRUE;
        }
      }
      if (!$primaryPreviewMedia && !empty($analysis['sorted'])) {
        $primaryPreviewMedia = $analysis['sorted'][0];
      }
      $primaryPreviewId = (int) ($primaryPreviewMedia['id'] ?? 0);
      $primaryPreviewPublic = (int) ($primaryPreviewMedia['is_public'] ?? 1) === 1;
      $primaryPreviewLabel = '';
      if ($primaryPreviewId > 0) {
        $primaryPreviewLabel = $this->sampleLabel((string) ($primaryPreviewMedia['source'] ?? ''), (string) ($primaryPreviewMedia['title'] ?? ''));
      }

      $rows[] = [
        'item_id' => $itemId,
        'item_title' => $title,
        'media_count' => count($medias),
        'eligible' => (bool) $analysis['eligible'],
        'reason' => (string) $analysis['reason'],
        'changed' => (bool) $analysis['changed'],
        'before' => $beforeSamples,
        'after' => $afterSamples,
        'primary_media_id' => $primaryPreviewId,
        'primary_media_label' => $primaryPreviewLabel,
        'primary_media_public' => $primaryPreviewPublic,
        'primary_media_fallback' => !$hasPublicMedia && $primaryPreviewId > 0,
      ];

      $plans[$itemId] = [
        'eligible' => (bool) $analysis['eligible'],
        'changed' => (bool) $analysis['changed'],
        'ordered_ids' => array_map(static fn($m) => (int) $m['id'], $analysis['sorted']),
      ];
    }

    usort($rows, static function (array $a, array $b) {
      return ((int) $a['item_id']) <=> ((int) $b['item_id']);
    });

    return [
      'token' => bin2hex(random_bytes(16)),
      'rows' => $rows,
      'plans' => $plans,
      'resolved_count' => count($resolved),
      'unresolved' => $unresolved,
      'ambiguous' => $ambiguous,
    ];
  }

  /**
   * Attach thumbnail URLs and compact labels to preview rows.
   */
  private function attachRowThumbs(array $rows, string $preferredSiteSlug = ''): array {
    $mediaIds = [];
    $itemIds = [];
    foreach ($rows as $row) {
      $itemIds[] = (int) ($row['item_id'] ?? 0);
      foreach (['before', 'after'] as $part) {
        foreach ((array) ($row[$part] ?? []) as $sample) {
          $mediaIds[] = (int) ($sample['id'] ?? 0);
        }
      }
    }
    $thumbs = $this->fetchThumbMap($mediaIds);
    $itemPublicUrls = $this->fetchPublicItemUrlMap($itemIds, $preferredSiteSlug);

    foreach ($rows as &$row) {
      $itemId = (int) ($row['item_id'] ?? 0);
      $row['public_item_url'] = (string) ($itemPublicUrls[$itemId] ?? '');
      foreach (['before', 'after'] as $part) {
        foreach ($row[$part] as &$sample) {
          $sampleId = (int) ($sample['id'] ?? 0);
          $sample['thumb'] = (string) ($thumbs[$sampleId] ?? '');
          $sample['label'] = $this->sampleLabel((string) ($sample['source'] ?? ''), (string) ($sample['title'] ?? ''));
        }
        unset($sample);
      }
    }
    unset($row);

    return $rows;
  }

  /**
   * Build item id => public item URL map with optional preferred site slug.
   */
  private function fetchPublicItemUrlMap(array $itemIds, string $preferredSiteSlug = ''): array {
    $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
    if (empty($itemIds)) {
      return [];
    }

    $urls = [];
    $resp = $this->api()->search('items', ['id' => $itemIds, 'limit' => count($itemIds)]);
    foreach ($resp->getContent() as $itemRep) {
      $itemId = (int) $itemRep->id();
      $urls[$itemId] = '';
      try {
        $itemSiteSlugs = [];
        if (method_exists($itemRep, 'sites')) {
          $sites = $itemRep->sites();
          if (is_array($sites)) {
            foreach ($sites as $siteRep) {
              if ($siteRep && method_exists($siteRep, 'slug')) {
                $slug = trim((string) ($siteRep->slug() ?? ''));
                if ($slug !== '') {
                  $itemSiteSlugs[] = $slug;
                }
              }
            }
          }
        }

        $siteSlug = '';
        if ($preferredSiteSlug !== '' && in_array($preferredSiteSlug, $itemSiteSlugs, TRUE)) {
          $siteSlug = $preferredSiteSlug;
        }
        elseif (!empty($itemSiteSlugs)) {
          $siteSlug = $itemSiteSlugs[0];
        }

        if ($siteSlug !== '') {
          $urls[$itemId] = (string) ($itemRep->siteUrl($siteSlug) ?? '');
        }
      }
      catch (\Throwable $e) {
        $urls[$itemId] = '';
      }
    }
    return $urls;
  }

  /**
   * Resolve a token to one or more item ids.
   */
  private function resolveItemIds(string $token): array {
    $token = trim($token);
    if ($token === '') {
      return [];
    }

    if (ctype_digit($token)) {
      $id = (int) $token;
      $exists = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM item WHERE id = :id', ['id' => $id], ['id' => \PDO::PARAM_INT]);
      return $exists > 0 ? [$id] : [];
    }

    $ids = $this->connection->fetchFirstColumn(
      'SELECT DISTINCT i.id
       FROM item i
       INNER JOIN value v ON v.resource_id = i.id
       INNER JOIN property p ON p.id = v.property_id
       INNER JOIN vocabulary voc ON voc.id = p.vocabulary_id
       WHERE voc.prefix = :vocab_prefix
         AND p.local_name = :local_name
         AND (v.value = :token OR v.uri = :token)
       LIMIT 5',
      [
        'vocab_prefix' => 'dcterms',
        'local_name' => 'identifier',
        'token' => $token,
      ],
      [
        'vocab_prefix' => \PDO::PARAM_STR,
        'local_name' => \PDO::PARAM_STR,
        'token' => \PDO::PARAM_STR,
      ]
    );
    $ids = array_values(array_unique(array_map('intval', $ids ?: [])));
    if (!empty($ids)) {
      return $ids;
    }

    $ids = $this->connection->fetchFirstColumn(
      'SELECT DISTINCT i.id
       FROM item i
       INNER JOIN value v ON v.resource_id = i.id
       WHERE v.value = :token OR v.uri = :token
       LIMIT 5',
      ['token' => $token],
      ['token' => \PDO::PARAM_STR]
    );
    $ids = array_values(array_unique(array_map('intval', $ids ?: [])));
    if (!empty($ids)) {
      return $ids;
    }

    $ids = $this->connection->fetchFirstColumn(
      'SELECT DISTINCT m.item_id
       FROM media m
       WHERE m.source LIKE :token
       LIMIT 5',
      ['token' => '%' . $token . '%'],
      ['token' => \PDO::PARAM_STR]
    );
    return array_values(array_unique(array_map('intval', $ids ?: [])));
  }

  /**
   * Analyze item media list and produce sorted plan for selected strategy.
   */
  private function analyzeItemMedia(array $medias, string $strategy): array {
    if (count($medias) < 2) {
      return [
        'eligible' => FALSE,
        'reason' => $this->translate('Less than 2 media.'),
        'changed' => FALSE,
        'sorted' => $medias,
      ];
    }

    $parsed = [];
    $baseKey = NULL;
    $hasPadded = FALSE;
    $hasPlain = FALSE;
    foreach ($medias as $m) {
      $source = (string) ($m['source'] ?? '');
      $filename = $this->extractFilename($source);
      if (!preg_match('/^(.*_)(\d+)(\.[^\.\/]+)$/u', $filename, $match)) {
        return [
          'eligible' => FALSE,
          'reason' => $this->translate('Unexpected filename pattern found.'),
          'changed' => FALSE,
          'sorted' => $medias,
        ];
      }

      $digits = (string) $match[2];
      $prefixExt = (string) ($match[1] . $match[3]);
      if ($baseKey === NULL) {
        $baseKey = $prefixExt;
      }
      elseif ($baseKey !== $prefixExt) {
        return [
          'eligible' => FALSE,
          'reason' => $this->translate('Multiple filename groups are mixed in one item.'),
          'changed' => FALSE,
          'sorted' => $medias,
        ];
      }

      $isPadded = strlen($digits) > 1 && $digits[0] === '0';
      if ($isPadded) {
        $hasPadded = TRUE;
      }
      else {
        $hasPlain = TRUE;
      }

      $m['num'] = (int) ltrim($digits, '0');
      if ($m['num'] === 0) {
        $m['num'] = 0;
      }
      $m['digits_len'] = strlen($digits);
      $m['is_padded'] = $isPadded ? 1 : 0;
      $parsed[] = $m;
    }

    if (!$hasPadded || !$hasPlain) {
      return [
        'eligible' => FALSE,
        'reason' => $this->translate('Both padded and non-padded groups are required.'),
        'changed' => FALSE,
        'sorted' => $medias,
      ];
    }

    $padded = array_values(array_filter($parsed, static fn($m) => (int) $m['is_padded'] === 1));
    $plain = array_values(array_filter($parsed, static fn($m) => (int) $m['is_padded'] === 0));

    usort($padded, static function (array $a, array $b) {
      $c = ((int) $a['num']) <=> ((int) $b['num']);
      if ($c !== 0) {
        return $c;
      }
      return ((int) $b['digits_len']) <=> ((int) $a['digits_len']);
    });
    usort($plain, static function (array $a, array $b) {
      $c = ((int) $a['num']) <=> ((int) $b['num']);
      if ($c !== 0) {
        return $c;
      }
      return ((int) $a['id']) <=> ((int) $b['id']);
    });

    $sorted = [];
    if ($strategy === 'plain_first') {
      $sorted = array_merge($plain, $padded);
    }
    elseif ($strategy === 'interleave') {
      $byNumPadded = [];
      foreach ($padded as $m) {
        $byNumPadded[(int) $m['num']][] = $m;
      }
      $byNumPlain = [];
      foreach ($plain as $m) {
        $byNumPlain[(int) $m['num']][] = $m;
      }
      $nums = array_values(array_unique(array_merge(array_keys($byNumPadded), array_keys($byNumPlain))));
      sort($nums);
      foreach ($nums as $num) {
        foreach (($byNumPadded[$num] ?? []) as $m) {
          $sorted[] = $m;
        }
        foreach (($byNumPlain[$num] ?? []) as $m) {
          $sorted[] = $m;
        }
      }
    }
    else {
      $sorted = array_merge($padded, $plain);
    }

    $beforeIds = array_map(static fn($m) => (int) $m['id'], $medias);
    $afterIds = array_map(static fn($m) => (int) $m['id'], $sorted);
    $changed = $beforeIds !== $afterIds;

    return [
      'eligible' => TRUE,
      'reason' => '',
      'changed' => $changed,
      'sorted' => $sorted,
    ];
  }

  /**
   * Extract source filename used for pattern parsing.
   */
  private function extractFilename(string $source): string {
    $source = trim($source);
    if ($source === '') {
      return '';
    }
    $path = parse_url($source, PHP_URL_PATH);
    $path = is_string($path) ? $path : $source;
    $path = trim($path, '/');
    if (substr($path, -10) === '/info.json') {
      $parts = explode('/', $path);
      if (count($parts) >= 2) {
        return (string) $parts[count($parts) - 2];
      }
    }
    return basename($path);
  }

  /**
   * Build media id => thumbnail URL map.
   */
  private function fetchThumbMap(array $mediaIds): array {
    $mediaIds = array_values(array_unique(array_filter(array_map('intval', $mediaIds))));
    if (empty($mediaIds)) {
      return [];
    }
    $thumbs = [];
    $resp = $this->api()->search('media', ['id' => $mediaIds, 'limit' => count($mediaIds)]);
    foreach ($resp->getContent() as $media) {
      $thumbs[(int) $media->id()] = (string) ($media->thumbnailUrl('square') ?? '');
    }
    return $thumbs;
  }

  /**
   * Build compact sample label for preview rows.
   */
  private function sampleLabel(string $source, string $title): string {
    $filename = $this->extractFilename($source);
    if ($filename !== '') {
      return $filename;
    }
    $title = trim($title);
    if ($title !== '') {
      return $title;
    }
    return '[no label]';
  }

}
