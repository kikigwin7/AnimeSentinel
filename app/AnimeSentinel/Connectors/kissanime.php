<?php

namespace App\AnimeSentinel\Connectors;

use App\Video;
use App\AnimeSentinel\Helpers;
use App\AnimeSentinel\Downloaders;
use Carbon\Carbon;

class kissanime
{
  /**
   * Finds all video's for the requested show.
   * Returns data as an array of models.
   *
   * @return array
   */
  public static function seek($show, $req_episode_num = null) {
    $videos = [];
    $processedLinks = [];

    // Try all alts to get a valid episode page
    foreach ($show->alts as $alt) {
      // Download search results page
      $page = Downloaders::downloadPage('http://kissanime.to/Search/Anime?keyword='.str_replace(' ', '+', $alt));

      // First check whether we already have an episode page
      if (strpos($page, '<meta name="description" content="Watch online and download ') !== false) {
        $link_stream = str_get_between($page, '<a Class="bigChar" href="', '">');
        if (!in_array($link_stream, $processedLinks)) {
          // Search for videos
          $videos = array_merge($videos, Self::seekEpisodes($page, $show, $alt, [
            'link_stream' => 'http://kissanime.to'.$link_stream,
            'translation_type' => (strpos(str_get_between($page, '<title>', '</title>'), '(Dub)') !== false ? 'dub' : 'sub'),
          ], $req_episode_num));
          $processedLinks[] = $link_stream;
          continue;
        }
      }

      // Otherwise, scrape and process search results
      $results = Helpers::scrape_page(str_get_between($page, '<tr style="height: 10px">', '</table>'), '</tr>', [
        'link_stream' => [true, '<a class="bigChar" href="', '">'],
        'title' => [false, '<a class="bigChar" href="{{link_stream}}">', '<'],
      ]);
      foreach ($results as $result) {
        // Determine translation type and clean up title
        $result['translation_type'] = 'sub';
        $result['title'] = trim(str_replace('(Sub)', '', $result['title']));
        if (strpos($result['title'], '(Dub)') !== false) {
          $result['translation_type'] = 'dub';
        }
        $result['title'] = trim(str_replace('(Dub)', '', $result['title']));

        if (match_fuzzy($alt, $result['title']) && !in_array($result['link_stream'], $processedLinks)) {
          // Search for videos
          $page = Downloaders::downloadPage('http://kissanime.to'.$result['link_stream']);
          $videos = array_merge($videos, Self::seekEpisodes($page, $show, $alt, [
            'link_stream' => 'http://kissanime.to'.$result['link_stream'],
            'translation_type' => $result['translation_type'],
          ], $req_episode_num));
          $processedLinks[] = $result['link_stream'];
        }
      }
    }

    return $videos;
    // Stream specific:   'show_id', 'streamer_id', 'translation_type', 'link_stream'
    // Episode specific:  'episode_num', 'link_episode', ('notes')
    // Video specific:    'uploadtime', 'link_video', 'resolution'
  }

  private static function seekEpisodes($page, $show, $alt, $data, $req_episode_num) {
    $videos = [];

    // Set some general data
    $data_stream = array_merge($data, [
      'show_id' => $show->id,
      'streamer_id' => 'kissanime',
    ]);

    // Scrape the page for episode data
    $episodes = Helpers::scrape_page(str_get_between($page, '<tr style="height: 10px">', '</table>'), '</td>', [
      'episode_num' => [true, 'Watch anime ', ' in high quality'],
      'link_episode' => [false, 'href="', '"'],
      'uploadtime' => [false, '<td>', ''],
    ]);

    // Find the lowest episode number
    foreach ($episodes as $episode) {
      if (strpos($episode['link_episode'], '/Episode-') !== false) {
        $ep_num = str_get_between($episode['episode_num'], 'Episode ', ' ');
        if (!isset($lowest_ep) || $ep_num < $lowest_ep) {
          $lowest_ep = (int) $ep_num;
        }
      }
    }
    if (!isset($lowest_ep) || $lowest_ep === 0) {
      $lowest_ep = 1;
    }

    // Get mirror data for each episode
    foreach ($episodes as $episode) {
      // Complete episode data
      $episode = Self::seekCompleteEpisode($episode, $lowest_ep - 1);
      if (empty($episode)) {
        continue;
      }

      elseif ($req_episode_num === null || $req_episode_num === (int) $episode['episode_num']) {
        // Get all mirrors data
        $mirrors = Self::seekMirrors($episode['link_episode']);
        // Loop through mirror list
        foreach ($mirrors as $mirror) {
          // Complete mirror data
          $mirror = Self::seekCompleteMirror($mirror);
          // Create and add final video
          $videos[] = new Video(array_merge($data_stream, $episode, $mirror));
        }
      }
    }

    return $videos;
  }

  private static function seekMirrors($link_episode) {
    // Get episode page
    $page = Downloaders::downloadPage($link_episode);
    // Scrape the page for mirror data
    $mirrors = Helpers::scrape_page(str_get_between($page, 'id="divDownload">', '</div>'), '</a>', [
      'link_video' => [true, 'href="', '"'],
      'resolution' => [false, '>', '.'],
    ]);
    return $mirrors;
  }

  private static function seekCompleteEpisode($episode, $decrement) {
    // Complete episode data
    if (strpos($episode['link_episode'], '/Episode-') !== false) {
      $line = $episode['episode_num'];
      $episode['episode_num'] = str_get_between($line, 'Episode ', ' ');
      $episode['notes'] = str_get_between($line, 'Episode '.$episode['episode_num'].' ', ' online');
    }
    elseif (strpos($episode['link_episode'], '/Movie?') !== false) {
      $episode['notes'] = str_get_between($episode['episode_num'], 'Movie ', ' online', true);
      $episode['episode_num'] = 1;
    }
    elseif (strpos($episode['link_episode'], '/Special?') !== false) {
      $episode['notes'] = str_get_between($episode['episode_num'], 'Special ', ' online', true);
      $episode['episode_num'] = 1;
    }
    else {
      return false;
    }
    $episode['episode_num'] -= $decrement;
    $episode['notes'] = str_replace('[', '(', str_replace(']', ')', $episode['notes']));
    $episode['link_episode'] = 'http://kissanime.to'.$episode['link_episode'];
    $episode['uploadtime'] = Carbon::createFromFormat('n/j/Y', trim($episode['uploadtime']))->hour(12)->minute(0)->second(0);
    return $episode;
  }

  private static function seekCompleteMirror($mirror) {
    // Complete mirror data
    return $mirror;
  }

  /**
   * Finds all episode data + title from the recently aired page.
   * Returns this data as an array of associative arrays.
   * This data is later used to find the episode video's.
   *
   * @return array
   */
  public static function guard() {
    $data = [];

    // Download the 'recently aired' page
    $page = Downloaders::downloadPage('http://kissanime.to');

    // Scrape the 'recently aired' page
    $dataRaw = Helpers::scrape_page(str_get_between($page, '<div class="items">', '<div class="clear">'), '</a>', [
      'link_stream' => [true, 'href="', '"'],
      'title' => [false, '<br />', '<br />'],
      'episode_num' => [false, '<span class=\'textDark\'>', '</span>'],
    ]);

    // Complete and return data
    foreach ($dataRaw as $item) {
      // Determine translation type and clean up title
      $item['translation_type'] = 'sub';
      $item['title'] = trim(str_replace('(Sub)', '', $item['title']));
      if (strpos($item['title'], '(Dub)') !== false) {
        $item['translation_type'] = 'dub';
      }
      $item['title'] = trim(str_replace('(Dub)', '', $item['title']));

      // Determine lowest episode number
      $page = Downloaders::downloadPage('http://kissanime.to/'.$item['link_stream']);
      // Scrape the page for episode data
      $episodes = Helpers::scrape_page(str_get_between($page, '<tr style="height: 10px">', '</table>'), '</td>', [
        'episode_num' => [true, 'Watch anime ', ' in high quality'],
        'link_episode' => [false, 'href="', '"'],
      ]);
      // Find the lowest episode number
      foreach ($episodes as $episode) {
        if (strpos($episode['link_episode'], '/Episode-') !== false) {
          $ep_num = str_get_between($episode['episode_num'], 'Episode ', ' ');
          if (!isset($lowest_ep) || $ep_num < $lowest_ep) {
            $lowest_ep = (int) $ep_num;
          }
        }
      }
      if (!isset($lowest_ep) || $lowest_ep === 0) {
        $lowest_ep = 1;
      }

      // Determine actual episode number
      if ($item['episode_num'] === 'Movie') {
        $item['episode_num'] = 1;
      }
      elseif (strpos($item['episode_num'], 'Episode ') !== false) {
        $episode_num = str_get_between($item['episode_num'], 'Episode ', ' ');
        if ($episode_num === false) {
          $episode_num = str_get_between($item['episode_num'], 'Episode ', '');
        }
        $item['episode_num'] = $episode_num - $lowest_ep + 1;
      }

      else {
        continue;
      }
      $data[] = $item;
    }

    return $data;
  }

  /**
   * Finds the stream link for the requested video.
   *
   * @return string
   */
  public static function findVideoLink($video) {
    // Get all mirrors data
    $mirrors = Self::seekMirrors($video->link_episode);

    // Loop through mirror list
    foreach ($mirrors as $mirror) {
      // Complete mirror data
      $mirror = Self::seekCompleteMirror($mirror);
      // Determine which link to return
      if ($mirror['resolution'] === $video->resolution) {
        return $mirror['link_video'];
      }
    }

    return $video->link_video;
  }
}
