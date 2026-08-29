<?php

namespace redundans\PodcastFetcher\Console;

use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use GuzzleHttp\Client;

class FetchJsonCommand extends Command
{
	protected $signature = 'noden:fetch-episodes';
	protected $description = 'Hämtar objekt från JSON-fil och skapar forumtrådar.';

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
			$content = html_entity_decode($rawContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');

			$duplicateIdentifier = "sync_source_id: " . $externalId;

			$exists = CommentPost::where('content', 'LIKE', "%{$duplicateIdentifier}%")->exists();

			if ($exists) {
				$this->line("Objektet med ID {$externalId} finns redan. Hoppar över.");
				continue;
			}
			
			try {
				$this->info("Hittade nytt avsnitt. Skapar tråd: \"{$title}\"...");

				$discussion = Discussion::start($title, $actor);
				$discussion->save();
				
				if (method_exists($discussion, 'tags')) {
					$discussion->tags()->sync([3]);
				}

				$content = strip_tags($content);
				$fullContent = $content . "\n\n" . $duplicateIdentifier;
				
				$post = new CommentPost();
				$post->discussion_id = $discussion->id;
				
				$formatter = $this->laravel->make('flarum.formatter');
				$post->content = $formatter->parse($fullContent, $post);
				
				$post->user_id       = $actor->id;
				$post->ip_address    = '127.0.0.1';
				$post->created_at    = \Carbon\Carbon::now();
				$post->type          = 'comment';
				$post->save();

				$discussion->refreshCommentCount();
				$discussion->refreshLastPost();
				$discussion->save();

				$this->info("Klart! Skapade tråd för ID {$externalId}.");
			} catch (\Throwable $dbError) {
				$this->error("Kraschade vid skapande av tråd: " . $dbError->getMessage());
				return;
			}
		}

		$this->info('JSON-synkronisering slutförd!');
	}
}
