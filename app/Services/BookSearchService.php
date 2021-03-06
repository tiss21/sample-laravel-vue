<?php
namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Google Books APIs を利用して書籍を検索する
 *
 * Class BookSearchService
 * @package App\Services
 */
class BookSearchService implements BookSearchServiceInterface
{
    /** @var string */
    private const API_URI = 'https://www.googleapis.com/books/v1/volumes';


    /**
     * 書籍を検索する
     *
     * @param string $searchValue
     * @return Collection
     */
    public function exec(string $searchValue): Collection
    {
        $responseArray = (true === \Config::get('app.mock.book_search'))
            ? $this->mockResponse($searchValue)
            : $this->requestApi($searchValue);

        if (true === is_null($responseArray)) {
            abort('500');
        }
        return $this->convert($responseArray);
    }


    /**
     * Google Books APIs へリクエストする
     *
     * @param string $searchValue
     * @return array|null
     */
    private function requestApi(string $searchValue): ?array
    {
        $query = $this->isIsbn($searchValue)
            ? sprintf('isbn:%s', str_replace('-', '', $searchValue))
            : urlencode($searchValue);

        $response = file_get_contents(sprintf('%s?q=%s&country=JP', static::API_URI, $query));
        if (false === $response) {
            return null;
        }
        return json_decode($response, true);
    }


    /**
     * $code が ISBN-10 or ISBN-13 であれば true を返す
     *
     * ISBN-10 [0-9]-[0-9]{2}-[0-9]{6}-[0-9X]
     * ISBN-13 (978|979)-[0-9]-[0-9]{2}-[0-9]{6}-[0-9]
     *
     * @param string $code
     * @return bool
     */
    private function isIsbn(string $code): bool
    {
        $pattern = '/^(978|979)?-?[0-9]-?[0-9]{2}-?[0-9]{6}-?[0-9X]$/';
        return (1 === preg_match($pattern, $code));
    }


    /**
     * API リクエスト モック版
     *
     * @param string $searchValue
     * @return array
     */
    private function mockResponse(string $searchValue): array
    {
        $json = '{"kind":"books#volumes","totalItems":1,"items":[{"kind":"books#volume","id":"Wx1dLwEACAAJ","etag":"fb5xInnoWtI","selfLink":"https://www.googleapis.com/books/v1/volumes/Wx1dLwEACAAJ","volumeInfo":{"title":"リーダブルコード","subtitle":"より良いコードを書くためのシンプルで実践的なテクニック","authors":["DustinBoswell","TrevorFoucher"],"publisher":"O\'ReillyMedia,Inc.","publishedDate":"2012-06","description":"読んでわかるコードの重要性と方法について解説","industryIdentifiers":[{"type":"ISBN_10","identifier":"4873115655"},{"type":"ISBN_13","identifier":"9784873115658"}],"readingModes":{"text":false,"image":false},"pageCount":237,"printType":"BOOK","averageRating":5.0,"ratingsCount":1,"maturityRating":"NOT_MATURE","allowAnonLogging":false,"contentVersion":"preview-1.0.0","imageLinks":{"smallThumbnail":"http://books.google.com/books/content?id=Wx1dLwEACAAJ&printsec=frontcover&img=1&zoom=5&source=gbs_api","thumbnail":"http://books.google.com/books/content?id=Wx1dLwEACAAJ&printsec=frontcover&img=1&zoom=1&source=gbs_api"},"language":"ja","previewLink":"http://books.google.co.jp/books?id=Wx1dLwEACAAJ&dq=isbn:9784873115658&hl=&cd=1&source=gbs_api","infoLink":"http://books.google.co.jp/books?id=Wx1dLwEACAAJ&dq=isbn:9784873115658&hl=&source=gbs_api","canonicalVolumeLink":"https://books.google.com/books/about/%E3%83%AA%E3%83%BC%E3%83%80%E3%83%96%E3%83%AB%E3%82%B3%E3%83%BC%E3%83%89.html?hl=&id=Wx1dLwEACAAJ"},"saleInfo":{"country":"JP","saleability":"NOT_FOR_SALE","isEbook":false},"accessInfo":{"country":"JP","viewability":"NO_PAGES","embeddable":false,"publicDomain":false,"textToSpeechPermission":"ALLOWED","epub":{"isAvailable":false},"pdf":{"isAvailable":false},"webReaderLink":"http://play.google.com/books/reader?id=Wx1dLwEACAAJ&hl=&printsec=frontcover&source=gbs_api","accessViewStatus":"NONE","quoteSharingAllowed":false},"searchInfo":{"textSnippet":"読んでわかるコードの重要性と方法について解説"}}]}';
        return json_decode($json, true);
    }


    /**
     *
     *
     * @param array $responseArray
     * @return Collection
     */
    private function convert(array $responseArray): Collection
    {
        if (false === array_key_exists('totalItems', $responseArray) ||
            false === array_key_exists('items', $responseArray)) {
            abort('500');
        }

        if (0 === $responseArray['totalItems']) {
            return Collection::make([]);
        }

        $convertedBooks = [];
        foreach ($responseArray['items'] as $book) {
            if (false === array_key_exists('volumeInfo', $book)) {
                continue;
            }
            $bookInfo = $book['volumeInfo'];

            $isbn = $this->findIsbn($bookInfo);
            if (true === is_null($isbn)) {
                // 識別子がない場合は取り扱わない
                continue;
            }

            $author = null;
            if (true === array_key_exists('authors', $bookInfo)) {
                $author = implode(',', $bookInfo['authors']);
            }

            $summary = null;
            if (true === array_key_exists('description', $bookInfo)) {
                $summary = $this->truncate($bookInfo['description'], 500);
            }

            $imageLink = null;
            if (true === array_key_exists('imageLinks', $bookInfo)) {
                if (true === array_key_exists('thumbnail', $bookInfo['imageLinks'])) {
                    $imageLink = $bookInfo['imageLinks']['thumbnail'];
                }
                if (true === array_key_exists('smallThumbnail', $bookInfo['imageLinks'])) {
                    $imageLink = $bookInfo['imageLinks']['smallThumbnail'];
                }
            }

            $convertedBooks[] = [
                'title'      => $bookInfo['title'],
                'subtitle'   => $bookInfo['subtitle'] ?? null,
                'author'     => $author,
                'publisher'  => $bookInfo['publisher'] ?? null,
                'release'    => $bookInfo['publishedDate'] ?? null,
                'summary'    => $summary,
                'isbn'       => $isbn,
                'image_link' => $imageLink,
                'language'   => $bookInfo['language'] ?? '',
            ];
        }
        return Collection::make($convertedBooks);
    }


    /**
     * ISBNを返す
     *
     * ISBN 13 が設定されていればこれを優先する
     * ISBN 13 が未設定 かつ ISBN 10 が設定されていれば ISBN 10 を返す
     *
     * @param array $bookInfo
     * @return string|null
     */
    private function findIsbn(array $bookInfo): ?string
    {
        if (false === array_key_exists('industryIdentifiers', $bookInfo)) {
            return null;
        }

        // ISBNの優先順
        $priority = [
            1 => 'ISBN_13',
            2 => 'ISBN_10',
        ];

        $isbns = [];
        foreach ($bookInfo['industryIdentifiers'] as $identifier) {
            foreach ($priority as $index => $type) {
                if ($type === $identifier['type']) {
                    $isbns[$index] = $identifier['identifier'];
                    break;
                }
            }
        }

        if (0 === count($isbns)) {
            return null;
        }

        ksort($isbns);
        return array_shift($isbns);
    }


    /**
     * 文字列を指定長に丸める
     *
     * @param string $input
     * @param int    $length
     * @param string $trimMarker
     * @return string
     */
    private function truncate(string $input, int $length = -1, string $trimMarker = '…'): string
    {
        $strLength = mb_strlen($input, 'UTF-8');
        if ($length < 0 || $strLength <= $length) {
            return $input;
        }
        return mb_substr($input, 0, $length - $strLength) . $trimMarker;
    }
}
