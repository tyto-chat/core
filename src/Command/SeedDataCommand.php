<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\User\CreateUserDto;
use App\Service\User\UserServiceInterface;
use App\Utils\ApiVersions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'tyto:seed', description: 'Seed the database with realistic test data via API')]
class SeedDataCommand extends Command
{
    private const ADMIN_EMAIL = 'seed.admin@tyto.test';
    private const ADMIN_PASSWORD = 'Seed@dmin1!';
    private const USER_COUNT = 1000;
    private const USER_PASSWORD = 'Seed1234!';
    private const USER_EMAIL_TEMPLATE = 'seed.user%d@tyto.test';
    private const BATCH_SIZE = 100;
    private const PUBLIC_TEXT_CHANNEL_MESSAGES = 50;
    private const PRIVATE_TEXT_CHANNEL_MESSAGES = 20;

    private const COMMUNITIES = [
        'The Developers Den', 'Gaming Lounge', 'Creative Studio', 'Tech Talk', 'Movie Buffs',
        'Fitness Freaks', 'Book Club', 'Music Corner', 'Travel Stories', 'Foodies United',
    ];
    private const TEXT_CHANNELS = [
        'general', 'announcements', 'off-topic', 'help', 'showcase',
        'resources', 'feedback', 'random', 'introductions', 'memes',
    ];
    private const VOICE_CHANNELS = ['General Voice', 'Gaming Room', 'Study Hall', 'Movie Night', 'Chill Zone'];
    private const PRIVATE_TEXT_CHANNEL_COUNT = 3;

    private const MESSAGE_TEMPLATES = [
        'Hey everyone! Just wanted to say hi 👋',
        "Has anyone tried the new update? It's pretty solid.",
        'Working on something cool, will share soon!',
        "Can someone help me with this? I'm stuck on a weird issue.",
        'Good morning! Hope everyone has a great day ☀️',
        'Just finished a long session, finally got it working 🎉',
        'Anyone else dealing with this? Seems like a common problem.',
        'Check this out: https://i.imgur.com/example.jpg',
        'Thoughts on this approach?',
        "I've been using this for a while now and it's great.",
        'lol this is too relatable 😂',
        'Not gonna lie, this took way longer than expected.',
        "Finally fixed that bug that's been haunting me all week.",
        "Anyone up for a call later? Let's discuss the project.",
        'This is incredibly helpful, thanks for sharing!',
        'Hot take: simplicity > complexity, always.',
        'Just discovered this trick and it changed everything.',
        'Reminder: meeting tomorrow at 3pm 📅',
        'Does anyone have experience with this? Any advice would be great.',
        'Week 3 of this project and honestly loving it.',
        "Small wins today — shipped a feature I've been working on.",
        'The documentation on this is really lacking, had to dig through the source.',
        'Anyone else using this stack? Would love to compare notes.',
        'Friendly reminder to take breaks and drink water 💧',
        'This repo is a goldmine: https://github.com/example/project',
        'Struggled with this for hours. Turns out it was a typo 🤦',
        "What's everyone's preferred approach for this?",
        'Just watched a great talk on this topic, highly recommend.',
        'Feature request: would love to see this added.',
        'The performance improvement after this change is insane.',
        'Quick question — is this the expected behavior?',
        'That feeling when the tests finally go green ✅',
        'Shipping code at midnight hits different.',
        'Opened a PR for review if anyone has a moment 🙏',
        'Really appreciate all the help here, this community is great.',
        'TIL you can do this with just one line. Mind blown.',
        'Weekend project update: made some good progress!',
        'Anyone else notice this changed in the latest version?',
        'This might be a dumb question, but... how do you handle this?',
        "Sharing my setup in case it's useful for anyone.",
        "```php\n\$result = array_map(fn(\$x) => \$x * 2, \$data);\n```\nThis is so much cleaner than a loop.",
        "```js\nconst data = await fetch('/api/items').then(r => r.json());\n```\nKeep it simple.",
        "```bash\ndocker compose up -d\n```\nDon't forget to add the `-d` flag!",
        'Let me know what you think — open to feedback.',
        'Closed that issue finally. Only took me 3 days 😅',
        'Paired with a colleague today and we got so much done.',
        'The rabbit hole I fell into today: fascinating but not productive 😂',
        'Does this violate the principle of least surprise? Genuinely asking.',
        "I wrote a blog post about this if anyone's interested.",
        'Good vibes only in here 🤙',
    ];

    private SymfonyStyle $io;
    private HttpClientInterface $client;

    public function __construct(
        private readonly UserServiceInterface $userService,
        private readonly HttpClientInterface $http,
        private readonly string $apiUrl,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->client = $this->http->withOptions([
            'verify_peer' => false,
            'verify_host' => false,
            'base_uri' => rtrim($this->apiUrl, '/'),
            'headers' => ['Content-Type' => 'application/ld+json', 'Accept' => 'application/ld+json'],
        ]);

        $this->io->title('Seeding test data');

        $this->io->section('Step 1/6 — Creating users via EntityManager');
        $this->createUsers();

        $this->io->section('Step 2/6 — Authenticating as seed admin');
        $adminToken = $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);

        $this->io->section('Step 3/6 — Creating communities via API');
        $communities = $this->createCommunities($adminToken);

        $this->io->section('Step 4/6 — Creating sections and channels via API');
        $channels = $this->createChannels($adminToken, $communities);

        $this->io->section('Step 5/6 — Users joining communities via API');
        $memberTokensByComm = $this->joinCommunities($communities);

        $this->io->section('Step 6/6 — Seeding messages via API');
        $this->seedMessages($channels, $memberTokensByComm);

        $this->io->success('Seeding complete!');

        return Command::SUCCESS;
    }

    private function createUsers(): void
    {
        $this->userService->new(new CreateUserDto(
            email: self::ADMIN_EMAIL,
            plainPassword: self::ADMIN_PASSWORD,
            displayName: 'Seed Admin',
        ), ['ROLE_ADMIN']);

        $faker = \Faker\Factory::create();
        $userDtos = (function () use ($faker): \Generator {
            for ($i = 1; $i <= self::USER_COUNT; ++$i) {
                yield new CreateUserDto(
                    email: sprintf(self::USER_EMAIL_TEMPLATE, $i),
                    plainPassword: self::USER_PASSWORD,
                    displayName: $faker->name(),
                );
            }
        })();

        $this->userService->createBatch(
            $userDtos,
            self::BATCH_SIZE,
            fn (int $count) => $this->io->writeln("  {$count}/".self::USER_COUNT.' users created'),
        );

        $this->io->writeln('  1 admin + '.self::USER_COUNT.' users created');
    }

    /** @return array<array{identifier: string, name: string}> */
    private function createCommunities(string $adminToken): array
    {
        $communities = [];

        foreach (self::COMMUNITIES as $name) {
            $response = $this->api('POST', '/api/'.ApiVersions::CANONICAL.'/communities', [
                'name' => $name,
                'isPrivate' => false,
            ], $adminToken);

            $communities[] = [
                'identifier' => $response['identifier'],
                'name' => $name,
            ];
            $this->io->writeln("  Created community: {$name} ({$response['identifier']})");
        }

        return $communities;
    }

    /**
     * @param array<array{identifier: string, name: string}> $communities
     *
     * @return array<array{communityIdentifier: string, channelIdentifier: string, type: string, isPrivate: bool}>
     */
    private function createChannels(string $adminToken, array $communities): array
    {
        $channels = [];

        foreach ($communities as $community) {
            $communityIri = '/api/'.ApiVersions::CANONICAL.'/communities/'.$community['identifier'];

            $textSection = $this->api('POST', '/api/'.ApiVersions::CANONICAL.'/sections', [
                'name' => 'Text Channels',
                'community' => $communityIri,
            ], $adminToken);

            $voiceSection = $this->api('POST', '/api/'.ApiVersions::CANONICAL.'/sections', [
                'name' => 'Voice Channels',
                'community' => $communityIri,
            ], $adminToken);

            $textSectionIri = '/api/'.ApiVersions::CANONICAL.'/sections/'.$textSection['id'];
            $voiceSectionIri = '/api/'.ApiVersions::CANONICAL.'/sections/'.$voiceSection['id'];

            $indices = range(0, count(self::TEXT_CHANNELS) - 1);
            shuffle($indices);
            $privateIndices = array_flip(array_slice($indices, 0, self::PRIVATE_TEXT_CHANNEL_COUNT));

            foreach (self::TEXT_CHANNELS as $j => $channelName) {
                $isPrivate = isset($privateIndices[$j]);
                $response = $this->api('POST', '/api/'.ApiVersions::CANONICAL.'/channels', [
                    'name' => $channelName,
                    'type' => 'text',
                    'community' => $communityIri,
                    'section' => $textSectionIri,
                    'isPrivate' => $isPrivate,
                ], $adminToken);

                $channels[] = [
                    'communityIdentifier' => $community['identifier'],
                    'channelIdentifier' => $response['identifier'],
                    'type' => 'text',
                    'isPrivate' => $isPrivate,
                ];
            }

            foreach (self::VOICE_CHANNELS as $channelName) {
                $this->api('POST', '/api/'.ApiVersions::CANONICAL.'/channels', [
                    'name' => $channelName,
                    'type' => 'audio',
                    'community' => $communityIri,
                    'section' => $voiceSectionIri,
                ], $adminToken);
            }

            $this->io->writeln('  Channels created for: '.$community['name']);
        }

        return $channels;
    }

    /**
     * @param array<array{identifier: string}> $communities
     *
     * @return array<string, list<string>> communityIdentifier => list of member tokens
     */
    private function joinCommunities(array $communities): array
    {
        $total = self::USER_COUNT;
        $communityIdentifiers = array_column($communities, 'identifier');
        $memberTokensByComm = array_fill_keys($communityIdentifiers, []);

        for ($i = 1; $i <= $total; ++$i) {
            $email = sprintf(self::USER_EMAIL_TEMPLATE, $i);

            try {
                $token = $this->login($email, self::USER_PASSWORD);
            } catch (\Throwable $e) {
                $this->io->warning("Could not login {$email}: ".$e->getMessage());
                continue;
            }

            $shuffled = $communityIdentifiers;
            shuffle($shuffled);
            $selected = array_slice($shuffled, 0, random_int(1, min(5, count($shuffled))));

            foreach ($selected as $identifier) {
                try {
                    $this->api('POST', '/api/'.ApiVersions::CANONICAL.'/communities/'.$identifier.'/members', null, $token);
                    $memberTokensByComm[$identifier][] = $token;
                } catch (\Throwable) {
                }
            }

            if (0 === $i % 100) {
                $this->io->writeln("  {$i}/{$total} users joined their communities");
            }
        }

        return $memberTokensByComm;
    }

    /**
     * @param array<array{communityIdentifier: string, channelIdentifier: string, type: string, isPrivate: bool}> $channels
     * @param array<string, list<string>>                                                                         $memberTokensByComm
     */
    private function seedMessages(array $channels, array $memberTokensByComm): void
    {
        $textChannels = array_filter($channels, fn ($c) => 'text' === $c['type']);
        $total = count($textChannels);
        $done = 0;

        foreach ($textChannels as $channel) {
            $communityIdentifier = $channel['communityIdentifier'];
            $channelIdentifier = $channel['channelIdentifier'];
            $isPrivate = $channel['isPrivate'];
            $count = $isPrivate ? self::PRIVATE_TEXT_CHANNEL_MESSAGES : self::PUBLIC_TEXT_CHANNEL_MESSAGES;

            $tokens = $memberTokensByComm[$communityIdentifier] ?? [];
            if (empty($tokens)) {
                continue;
            }

            $path = '/api/'.ApiVersions::CANONICAL.'/communities/'.$communityIdentifier.'/channels/'.$channelIdentifier.'/messages';

            for ($m = 0; $m < $count; ++$m) {
                $token = $tokens[array_rand($tokens)];
                $text = self::MESSAGE_TEMPLATES[array_rand(self::MESSAGE_TEMPLATES)];

                try {
                    $this->api('POST', $path, ['text' => $text], $token);
                } catch (\Throwable) {
                }
            }

            ++$done;
            if (0 === $done % 10 || $done === $total) {
                $this->io->writeln("  {$done}/{$total} channels seeded");
            }
        }
    }

    private function login(string $email, string $password): string
    {
        $response = $this->client->request('POST', '/auth', [
            'json' => ['email' => $email, 'password' => $password],
        ]);

        $data = $response->toArray();

        if (!isset($data['token'])) {
            throw new \RuntimeException("Login failed for {$email}: ".json_encode($data, \JSON_THROW_ON_ERROR));
        }

        return $data['token'];
    }

    /** @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function api(string $method, string $path, ?array $body, string $token): array
    {
        $options = ['auth_bearer' => $token];
        if (null !== $body) {
            $options['json'] = $body;
        }

        $response = $this->client->request($method, $path, $options);
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 400) {
            throw new \RuntimeException("{$method} {$path} failed [{$statusCode}]: ".$response->getContent(false));
        }

        return $response->toArray(false);
    }
}
