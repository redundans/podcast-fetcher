<?php

namespace Acme\JsonPosts\Console;
namespace redundans\PodcastFetcher\Console;

use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
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
		} catch (\Exception $e) {
			$this->error('Kunde inte hämta JSON-filen: ' . $e->getMessage());
			return;
		}

		if (empty($items)) {
			$this->info('Inga objekt hittades i JSON-filen.');
			return;
		}

		foreach ($items as $item) {
			$externalId = Arr::get($item, 'id');
			$title = Arr::get($item, 'title');
			$content = Arr::get($item, 'content');

			$duplicateIdentifier = "<!-- json_id: $externalId -->";

			$exists = CommentPost::where('content', 'LIKE', "%{$duplicateIdentifier}%")->exists();

			if ($exists) {
				$this->line("Objektet med ID {$externalId} finns redan. Hoppar över.");
				continue;
			}

			$discussion = Discussion::start($title, $actor);
			$discussion->raise(new \Flarum\Tags\Event\DiscussionWillBeTagged($discussion, $actor, [3]));

			$discussion->save();

			$fullContent = $content . "\n\n" . $duplicateIdentifier;
			
			$post = CommentPost::reply(
				$discussion->id,
				$fullContent,
				$actor->id,
				'127.0.0.1'
			);

			$post->save();

			$discussion->refreshCommentCount();
			$discussion->refreshLastPost();
			$discussion->save();

			$this->info("Skapade ny tråd: \"{$title}\"");
		}

		$this->info('JSON-synkronisering slutförd!');
	}
}
