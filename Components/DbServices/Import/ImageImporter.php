<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shopware\SwagMigration\Components\DbServices\Import;

use Enlight_Components_Db_Adapter_Pdo_Mysql as PDOConnection;
use Shopware\Components\Logger;
use Shopware\Components\Model\ModelManager;
use Shopware\Models\Media\Media;
use Shopware\Models\Article\Article;
use Shopware\Models\Article\Image;
use Shopware\Models\Article\Repository as ArticleRepository;
use Symfony\Component\HttpFoundation\File\File;

class ImageImporter
{
    /* @var ArticleRepository $articleRepository */
    private $articleRepository = null;

    /* @var PDOConnection $db */
    private $db = null;

    /* @var ModelManager $em */
    private $em = null;

    /* @var Logger $logger */
    private $logger;

    /**
     * ImageImporter constructor.
     *
     * @param PDOConnection $db
     * @param ModelManager $em
     * @param Logger $logger
     */
    public function __construct(PDOConnection $db, ModelManager $em, Logger $logger)
    {
        $this->em = $em;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * @return ArticleRepository
     */
    private function getArticleRepository()
    {
        if ($this->articleRepository === null) {
            $this->articleRepository = $this->em->getRepository('Shopware\Models\Article\Article');
        }

        return $this->articleRepository;
    }

    /**
     * @param array $image
     * @return int
     */
    public function importArticleImage(array $image)
    {
        if (empty($image) || !is_array($image)) {
            return false;
        }

        $image = $this->prepareImageData($image);
        if (empty($image['articleID']) || (empty($image['image']) && empty($image['name']))) {
            return false;
        }

        $image['main'] = $this->setMain($image['main'], $image['articleID']);

        $uploadFile = $this->copyImage($image['image'], $image['name']);
        if ($uploadFile === false) {
            return false;
        }

        $media = new Media();
        $file = new File($uploadFile);

        $identity = Shopware()->Auth()->getIdentity();
        $userId = ($identity !== null) ? $identity->id : 0;
        $media->setUserId($userId);

        $media->setDescription($image['description']);
        $media->setCreated(new \DateTime());
        $media->setFile($file);

        /* @var ArticleRepository $articleRepository */
        $articleRepository = $this->getArticleRepository();
        $article = $articleRepository->find((int) $image['articleID']);
        if (!$article instanceof Article) {
            $this->logger->error("Article '{$image['articleID']}' not found!");

            return false;
        }

        $media->setAlbumId($image['albumID']);
        /* @var \Shopware\Models\Media\Album $album */
        $album = $this->em->find('Shopware\Models\Media\Album', $image['albumID']);
        $media->setAlbum($album);

        $articleImage = new Image();
        list($width, $height) = getimagesize($uploadFile);
        $articleImage->setDescription($image['description']);
        $articleImage->setMedia($media);
        $articleImage->setArticle($article);
        $articleImage->setWidth($width);
        $articleImage->setHeight($height);
        $articleImage->setPath($image['name']);
        $articleImage->setExtension($media->getExtension());
        $articleImage->setPosition($image['position']);
        $articleImage->setMain($image['main']);
        $articleImage->setRelations($image['relations']);

        $article->setImages($articleImage);

        $this->em->persist($media);
        $this->em->persist($article);
        $this->em->persist($articleImage);
        $this->em->flush();

        return $articleImage->getId();
    }

    /**
     * @param array $image
     * @return array
     */
    private function prepareImageData(array $image)
    {
        if (isset($image['link'])) {
            $image['image'] = $image['link'];
        }
        if (isset($image['articleID'])) {
            $image['articleID'] = intval($image['articleID']);
        }
        if (empty($image['description'])) {
            $image['description'] = '';
        }
        if (empty($image['relations'])) {
            $image['relations'] = '';
        }

        $image['albumID'] = isset($image['albumID']) ? (int) $image['albumID'] : -1;
        $image['position'] = !empty($image['position']) ? intval($image['position']) : 0;
        $image['name'] = empty($image['name']) ? md5(uniqid(mt_rand(), true)) : pathinfo($image['name'], PATHINFO_FILENAME);

        return $image;
    }

    /**
     * @param int|string $main
     * @param int $articleId
     * @return int
     */
    private function setMain($main, $articleId)
    {
        if (!empty($main) && $main == 1) {
            $main = 1;
        } elseif (!empty($main)) {
            $main = 2;
        }

        if (empty($main)) {
            $sql = "SELECT id
                    FROM s_articles_img
                    WHERE articleID = $articleId
                      AND main = 1";
            $imageId = $this->db->fetchOne($sql);
            $main = empty($imageId) ? 1 : 2;
        } elseif ($main == 1) {
            $sql = "UPDATE s_articles_img
                    SET main = 2
                    WHERE articleID = $articleId";
            $this->db->query($sql);
        }

        return $main;
    }

    /**
     * @param string $image
     * @param string $name
     * @return bool|string
     */
    private function copyImage($image, $name)
    {
        $uploadDir = Shopware()->DocPath('media_' . 'temp');
        $ext = "";
        if (!empty($image)) {
            foreach (['.png', '.gif', '.jpg'] as $extension) {
                if(stristr($image, $extension) !== FALSE) {
                    $ext = $extension;
                    break;
                }
            }

            $uploadFile = $uploadDir . $name . $ext;
            if (!copy($image, $uploadFile)) {
                $this->logger->error("Copying image from '$image' to '$uploadFile' did not work!");

                return false;
            }

            if (getimagesize($uploadFile) === false) {
                unlink($uploadFile);
                $this->logger->error("The file '$uploadFile' is not a valid image!");

                return false;
            }
        } else {
            foreach (['.png', '.gif', '.jpg'] as $extension) {
                if (file_exists($uploadDir . $name . $extension)) {
                    $uploadFile = $uploadDir . $name . $extension;
                    break;
                }
            }

            if (empty($uploadFile)) {
                $this->logger->error("Image source '$uploadFile' not found!");

                return false;
            }
        }

        return $uploadFile;
    }
}