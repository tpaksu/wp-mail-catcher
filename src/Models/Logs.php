<?php

namespace WpMailCatcher\Models;

use WpMailCatcher\GeneralHelper;

class Logs
{
    static public $postsPerPage = 10;

    static public function getTotalPages()
    {
        return ceil(self::getTotalAmount() / self::$postsPerPage);
    }

    /**
     * @param array $args
     * @return array|null|object
     */
    static public function get($args = [])
    {
        global $wpdb;

        $cachedValue = Cache::get($args);

        if ($cachedValue != null) {
            return $cachedValue;
        }

        /**
         * Set default arguments and combine with
         * those passed in get/post and passed directly
         * to the function
         */
        $defaults = [
            'orderby' => 'time',
            'posts_per_page' => self::$postsPerPage,
            'paged' => 1,
            'order' => 'DESC',
            'date_time_format' => 'human',
            'post_status' => 'any',
            'subject' => null,
            'post__in' => []
        ];

        $args = array_merge($defaults, $args);

        /**
         * Sanitise each value in the array
         */
        array_walk_recursive($args, 'WpMailCatcher\GeneralHelper::sanitiseForQuery');

        $sql = "SELECT id, time, email_to, subject, message,
            status, error, backtrace_segment, attachments,
            additional_headers
            FROM " . $wpdb->prefix . GeneralHelper::$tableName . " ";

        $whereClause = false;

        if (!empty($args['post__in'])) {
            $whereClause = true;
            $sql .= "WHERE id IN(" . GeneralHelper::arrayToString($args['post__in']) . ") ";
        }

        if ($args['subject'] != null) {
            if ($whereClause == true) {
                $sql .= "AND ";
            } else {
                $sql .= "WHERE ";
                $whereClause = true;
            }

            $sql .= "subject LIKE '%" . $args['subject'] . "%' ";
        }

        if ($args['post_status'] != 'any') {
            if ($whereClause == true) {
                $sql .= "AND ";
            } else {
                $sql .= "WHERE ";
                $whereClause = true;
            }

            switch ($args['post_status']) {
                case ('successful') :
                    $sql .= "status = 1 ";
                    break;
                case ('failed') :
                    $sql .= "status = 0 ";
                    break;
            }
        }

        $sql .= "ORDER BY " . $args['orderby'] . " " . $args['order'] . " ";

        if ($args['posts_per_page'] != -1) {
            $sql .= "LIMIT " . $args['posts_per_page'] . "
               OFFSET " . ($args['posts_per_page'] * ($args['paged'] - 1));
        }

        return Cache::set($args, self::dbResultTransform($wpdb->get_results($sql, ARRAY_A), $args));
    }

    static private function dbResultTransform($results, $args = [])
    {
        foreach ($results as &$result) {
            $result['status'] = (bool)$result['status'];
            $result['attachments'] = json_decode($result['attachments'], true);
            $result['additional_headers'] = json_decode($result['additional_headers'], true);
            $result['attachment_file_paths'] = [];

            if (is_string($result['additional_headers'])) {
                $result['additional_headers'] = explode(PHP_EOL, $result['additional_headers']);
            }

            $result['timestamp'] = $result['time'];
            $result['time'] = $args['date_time_format'] == 'human' ? GeneralHelper::getHumanReadableTimeFromNow($result['time']) : date($args['date_time_format']);
            $result['is_html'] = GeneralHelper::doesArrayContainSubString($result['additional_headers'], 'text/html');
            $result['email_from'] = self::getEmailFrom($result);
            $result['message'] = stripslashes(htmlspecialchars_decode($result['message']));

            if (!empty($result['attachments'])) {
                foreach ($result['attachments'] as &$attachment) {
                    if ($attachment['id'] == -1) {
                        $attachment['note'] = GeneralHelper::$attachmentNotInMediaLib;
                        continue;
                    }

                    $attachment['src'] = GeneralHelper::$attachmentNotImageThumbnail;
                    $attachment['url'] = wp_get_attachment_url($attachment['id']);
                    $result['attachment_file_paths'][] = get_attached_file($attachment['id']);

                    $isImage = strpos(get_post_mime_type($attachment['id']), 'image') !== false ? true : false;

                    if ($isImage == true) {
                        $attachment['src'] = $attachment['url'];
                    }
                }
            }
        }

        return $results;
    }

    static public function getTotalAmount()
    {
        global $wpdb;

        return $wpdb->get_var("SELECT COUNT(*) FROM " . $wpdb->prefix . GeneralHelper::$tableName);
    }

    static public function delete($ids)
    {
        global $wpdb;

        $ids = GeneralHelper::arrayToString($ids);
        $ids = GeneralHelper::sanitiseForQuery($ids);

        $wpdb->query("DELETE FROM " . $wpdb->prefix . GeneralHelper::$tableName . "
                      WHERE id IN(" . $ids . ")");
    }

    static public function truncate()
    {
        global $wpdb;

        $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . GeneralHelper::$tableName);
    }

    static private function getEmailFrom($logEntry)
    {
        $fullHeader = GeneralHelper::searchForSubStringInArray($logEntry['additional_headers'], 'From: ');

        /**
         * Need to replace "custom:" as well due to a bug in previous versions
         * that caused the header to save as "custom: from: example@test.com"
         */
        return str_replace(['custom:', 'From:', ' '], '', $fullHeader);
    }
}
