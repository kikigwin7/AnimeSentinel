<?php

namespace App\AnimeSentinel\Connectors;

use App\Video;
use App\AnimeSentinel\Helpers;
use App\AnimeSentinel\Downloaders;
use App\AnimeSentinel\Actions\VideoManager;

class BaseConnector
{
  protected static $useDecrements = false;
  protected static $page_main = '';
  protected static $page_recent = '';
  protected static $ide_invalidPage = '';

  /**
  * Find the URL's for the pages that contains the episode listing, per translation type.
  *
  * @return array (translation_type, link_stream, page)
  */
  public static function findStreamPages($show) {
    return [];
  }

  /**
  * Scrape a stream page to get episode data.
  *
  * @return array
  */
  protected static function scrapeStreamPage($page) {
    return [];
  }

  /**
  * Complete the data for a specific episode.
  *
  * @return array
  */
  protected static function completeEpisodeData($episode) {
    return $episode;
  }

  /**
  * Scrape an episode page to get mirror data.
  *
  * @return array
  */
  protected static function scrapeEpisodePage($page, $link_episode) {
    return [];
  }

  /**
  * Complete the data for a specific mirror.
  *
  * @return array
  */
  protected static function completeMirrorData($mirror) {
    return $mirror;
  }

  /**
  * Find and save all videos for the requested show,
  * and optionally the requested translation types and/or episode number.
  *
  * @return integer
  */
  public static function findVideosForShow($show, $req_translation_types = null, $req_episode_num = null) {
    $amount = 0;

    // Find all stream pages
    $streamPages = Static::findStreamPages($show);
    // dd($streamPages);

    foreach ($streamPages as $streamPage) {
      // If the stream page has the requested translation type
      if ($req_translation_types === null || $streamPage['translation_type'] === 'all' || in_array($streamPage['translation_type'], $req_translation_types)) {

        // Find all episodes
        $episodes = Static::findEpisodes($streamPage['page']);
        // dd($episodes);

        foreach ($episodes as $episode) {
          // If this is the requested episode
          if ($req_episode_num === null || $episode['episode_num'] === $req_episode_num) {

            // Find all mirrors
            $mirrors = Static::findMirrors($episode['link_episode']);
            // dd($mirrors);

            foreach ($mirrors as $mirror) {
              // Create the video
              $video = new Video(array_merge($mirror, $episode));
              $video->link_stream = $streamPage['link_stream'];
              if (!isset($video->translation_type)) {
                $video->translation_type = $streamPage['translation_type'];
              }
              $video->show_id = $show->id;
              $video->streamer_id = str_get_between(Static::class, '\\', '', true);

              // dd($video);

              // Save the video
              VideoManager::saveVideos($video);
              $amount++;
            }
          }
        }
      }
    }

    return $amount;
  }

  /**
  * Find data for all episodes on the stream page.
  *
  * @return array (episode_num, link_episode) (translation_type, notes, uploadtime)
  */
  public static function findEpisodes($page) {
    // Scrape the page for episode data
    $episodes = Static::scrapeStreamPage($page);

    foreach ($episodes as $index => $episode) {
      // Complete episode data
      $episode = Static::completeEpisodeData($episode);
      // Set or unset episode
      if (empty($episode)) {
        unset($episodes[$index]);
      } else {
        $episode['episode_num'] = (int) $episode['episode_num'];
        $episodes[$index] = $episode;
      }
    }

    // If this streamer uses decrements, find it and apply to all episodes
    if (Static::$useDecrements) {
      $decrement = Static::findDecrement($episodes);
      foreach ($episodes as $index => $episode) {
        $episodes[$index]['episode_num'] -= $decrement;
      }
    }

    return $episodes;
  }

  /**
  * Find data for all mirrors on the episode page.
  *
  * @return array (link_video, resolution, mirror_id) (translation_type, notes, uploadtime)
  */
  public static function findMirrors($link_episode) {
    $page = Downloaders::downloadPage($link_episode);
    if (str_contains($page, Static::$ide_invalidPage)) {
      return [];
    }

    // Scrape the page for mirror data
    $mirrors = Static::scrapeEpisodePage($page, $link_episode);

    foreach ($mirrors as $index => $mirror) {
      // Complete mirror data
      $mirror = Static::completeMirrorData($mirror);
      // Set or unset mirror
      if (empty($mirror)) {
        unset($mirrors[$index]);
      } else {
        $mirrors[$index] = $mirror;
      }
    }

    return $mirrors;
  }

  /**
  * Find the required decrement from a list of episodes.
  *
  * @return integer
  */
  protected static function findDecrement($episodes) {
    foreach ($episodes as $episode) {
      if (!isset($lowest_ep) || $episode['episode_num'] < $lowest_ep) {
        $lowest_ep = $episode['episode_num'];
      }
    }
    if (!isset($lowest_ep) || $lowest_ep < 1) {
      return 0;
    }
    return $lowest_ep - 1;
  }

  /**
  * Find data for all episodes on this streamer's recently aired page.
  *
  * @return array (title, episode_num) (translation_type)
  */
  public static function findRecentlyAired() {
    return [];
  }
}

// Stream specific:   'show_id', 'streamer_id', 'link_stream'
// Episode specific:  'episode_num', 'translation_type', 'link_episode'
// Video specific:    'uploadtime', 'link_video', 'resolution', ('notes'), 'mirror_id'