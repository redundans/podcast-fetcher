<?php

namespace redundans\PodcastFetcher\Console;

use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Flarum\Tags\Tag;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use GuzzleHttp\Client;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

class FetchJsonCommand extends Command
{
	protected $signature = 'noden:fetch-episodes';
	protected $description = 'Hämtar objekt från JSON-fil och skapar forumtrådar.';
	protected $assetsDisk;

	public function __construct(FilesystemFactory $filesystem)
	{
		parent::__construct();
		$this->assetsDisk = $filesystem->disk('flarum-assets');
	}

	public function handle()
	{
		$this->info('Startar hämtning av JSON...');

		$jsonUrl = 'https://app.radionoden.se/app/episodes.json';
		$actor = User::find(1);

		if (!$actor) {
			$this->error('Kunde inte hitta användaren med ID 1.');
			return;
		}

		$client = new Client();
		try {
			$response = $client->get($jsonUrl);
			$items = json_decode($response->getBody()->getContents(), true);
		} catch (\Throwable $e) {
			$this->error('Kunde inte hämta JSON-filen: ' . $e->getMessage());
			return;
		}

		if (empty($items)) {
			$this->info('Inga objekt hittades i JSON-filen.');
			return;
		}

		foreach ($items as $item) {
			$externalId = Arr::get($item, 'linkid');
			$title = Arr::get($item, 'name');
			$rawContent = Arr::get($item, 'description');
			$pod = Arr::get($item, 'pod');
			$image_url = $pod['icon'];
			$linkposter_url = Arr::get($item, 'url');
			$content = html_entity_decode($rawContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $publishedString = Arr::get($item, 'published');
            $publishedDate = $publishedString ? new \DateTime($publishedString) : new \DateTime();

			$duplicateIdentifier = "sync_source_id: " . $externalId;

			$exists = Discussion::where('linkposter_url', $linkposter_url)->exists();

			if ($exists) {
				$this->line("Objektet med ID {$externalId} finns redan. Hoppar över.");
				continue;
			}

			try {
				$this->info("Hittade nytt avsnitt. Skapar tråd: \"{$title}\"...");

				$discussion = Discussion::start($title, $actor);
                $discussion->linkposter_description = $content;
                $discussion->linkposter_url = Arr::get($item, 'url');
                $discussion->created_at = $publishedDate;
				$discussion->save();

				$content = strip_tags($content);

				$post = new CommentPost();
				$post->discussion_id = $discussion->id;

				$post->content       = '';
				$post->user_id       = $actor->id;
				$post->ip_address    = '127.0.0.1';
				$post->created_at    = \Carbon\Carbon::now();
				$post->type          = 'comment';
                $post->created_at = $publishedDate;
				$post->save();

				if ($image_url) {
					$clean_name = basename(parse_url($image_url, PHP_URL_PATH));
					$filename = time() . '_' . (preg_replace('/[^a-zA-Z0-9_.-]/', '', $clean_name) ?: 'thumb.jpg');

					try {
						$client = new Client(['timeout' => 5.0]);
						$response = $client->get($image_url);
						$image_content = $response->getBody()->getContents();
						$manager = new ImageManager(new GdDriver());
						$image = $manager->read($image_content);
						$thumbnail = $image->cover(150, 150);
						$thumbnail_encoded = $thumbnail->encode(new \Intervention\Image\Encoders\JpegEncoder(75))->toString();

						if ($discussion->linkposter_thumbnail && $this->assetsDisk->has("linkposter/{$discussion->linkposter_thumbnail}")) {
							$this->assetsDisk->delete("linkposter/{$discussion->linkposter_thumbnail}");
						}

						$this->assetsDisk->put("linkposter/{$filename}", $thumbnail_encoded);
						$discussion->linkposter_thumbnail = $filename;
					} catch (\Exception $e) {
						resolve('log')->error('Linkposter downloading of thumbnail did not succeed: ' . $e->getMessage());
						if (!$discussion->exists) {
							$discussion->linkposter_thumbnail = null;
						}
					}
				} else if (!$discussion->exists) {
					$discussion->linkposter_thumbnail = null;
				}

				$discussion->refreshCommentCount();
                $discussion->setFirstPost($post);
				$discussion->refreshLastPost();
				$discussion->save();

				try {
					$db = app('flarum.db');

					$tagId = $db->table('tags')
						->where('slug', 'podcasts')
						->value('id');

					if ($tagId) {
						$db->table('discussion_tag')
							->where('discussion_id', $discussion->id)
							->delete();

						$db->table('discussion_tag')->insert([
							'discussion_id' => $discussion->id,
							'tag_id'        => $tagId
						]);

						$this->info("Kopplade tråden till taggen (ID: {$tagId}) direkt i databasen.");
					} else {
						$this->error("Kunde inte hitta någon tagg med sluggen 'poddar' i databasen.");
					}
				} catch (\Throwable $tagError) {
					$this->warn("Kunde inte synka tagg via databasen: " . $tagError->getMessage());
				}

				$this->info("Klart! Skapade tråd för ID {$externalId}.");
			} catch (\Throwable $dbError) {
				$this->error("Kraschade vid skapande av tråd: " . $dbError->getMessage());
				return;
			}
		}

		$this->info('JSON-synkronisering slutförd!');
	}
}
