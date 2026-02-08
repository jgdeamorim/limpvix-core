<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Adapters\Scheduling;

/**
 * Adapter: MediaStorageAdapter
 *
 * Gerencia upload de mídia para WordPress Media Library.
 * Usado para fotos/vídeos de check-in e check-out.
 */
final class MediaStorageAdapter
{
    /**
     * Faz upload de foto para Media Library
     *
     * @param array $file Array do $_FILES
     * @param string $scheduleUuid
     * @return string URL da mídia
     */
    public function uploadPhoto(array $file, string $scheduleUuid): string
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Validar é imagem
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            throw new \InvalidArgumentException('File must be an image (jpg, png, webp)');
        }

        // Upload
        $attachmentId = media_handle_sideload($file, 0);

        if (is_wp_error($attachmentId)) {
            throw new \RuntimeException('Failed to upload photo: ' . $attachmentId->get_error_message());
        }

        // Adicionar metadata
        update_post_meta($attachmentId, '_limpvix_schedule_uuid', $scheduleUuid);
        update_post_meta($attachmentId, '_limpvix_media_type', 'check_in_photo');

        return wp_get_attachment_url($attachmentId);
    }

    /**
     * Faz upload de vídeo para Media Library
     *
     * @param array $file Array do $_FILES
     * @param string $scheduleUuid
     * @param int $maxSizeMB Tamanho máximo em MB (default: 10MB)
     * @return string URL da mídia
     */
    public function uploadVideo(array $file, string $scheduleUuid, int $maxSizeMB = 10): string
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Validar é vídeo
        $allowedTypes = ['video/mp4', 'video/quicktime', 'video/x-m4v'];
        if (!in_array($file['type'], $allowedTypes)) {
            throw new \InvalidArgumentException('File must be a video (mp4, mov, m4v)');
        }

        // Validar tamanho
        $maxSizeBytes = $maxSizeMB * 1024 * 1024;
        if ($file['size'] > $maxSizeBytes) {
            throw new \InvalidArgumentException(
                sprintf('Video size exceeds %dMB limit', $maxSizeMB)
            );
        }

        // Upload
        $attachmentId = media_handle_sideload($file, 0);

        if (is_wp_error($attachmentId)) {
            throw new \RuntimeException('Failed to upload video: ' . $attachmentId->get_error_message());
        }

        // Adicionar metadata
        update_post_meta($attachmentId, '_limpvix_schedule_uuid', $scheduleUuid);
        update_post_meta($attachmentId, '_limpvix_media_type', 'check_in_video');

        return wp_get_attachment_url($attachmentId);
    }

    /**
     * Busca URL de uma mídia por ID
     *
     * @param int $mediaId
     * @return string URL
     */
    public function getUrl(int $mediaId): string
    {
        $url = wp_get_attachment_url($mediaId);

        if (!$url) {
            throw new \RuntimeException('Media not found: ' . $mediaId);
        }

        return $url;
    }

    /**
     * Busca todas mídias de um Schedule
     *
     * @param string $scheduleUuid
     * @return array Array de URLs
     */
    public function getMediaBySchedule(string $scheduleUuid): array
    {
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'meta_query' => [
                [
                    'key' => '_limpvix_schedule_uuid',
                    'value' => $scheduleUuid,
                ],
            ],
            'posts_per_page' => -1,
        ];

        $query = new \WP_Query($args);
        $urls = [];

        foreach ($query->posts as $post) {
            $urls[] = wp_get_attachment_url($post->ID);
        }

        return $urls;
    }

    /**
     * Deleta mídia
     *
     * @param int $mediaId
     */
    public function deleteMedia(int $mediaId): void
    {
        wp_delete_attachment($mediaId, true);
    }
}
