<?php

namespace App;

use AmoCRM\Exceptions\InvalidArgumentException;
use AmoCRM\Collections\NotesCollection;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Models\NoteType\CommonNote;
use AmoCRM\Exceptions\AmoCRMMissedTokenException;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Filters\EventsFilter;
use AmoCRM\Client\AmoCRMApiClient;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Symfony\Component\Dotenv\Dotenv;

class AmoCRMController
{
    protected $postData;
    protected $apiClient;

    public function __construct()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $this->postData = $input ?: $_POST;

        $dotenv = new Dotenv();
        $dotenv->load(__DIR__ . '/../../.env');

        $clientId = $_ENV['CLIENT_ID'];
        $clientSecret = $_ENV['CLIENT_SECRET'];
        $redirectUri = $_ENV['CLIENT_REDIRECT_URI'];

        $this->apiClient = new AmoCRMApiClient($clientId, $clientSecret, $redirectUri);

        $accessToken = AmoCRMTokenActions::getToken();
        $this->apiClient->setAccessToken($accessToken)
            ->setAccountBaseDomain($accessToken->getValues()['baseDomain'])
            ->onAccessTokenRefresh(
                function (AccessTokenInterface $accessToken, string $baseDomain) {
                    saveToken(
                        [
                            'accessToken' => $accessToken->getToken(),
                            'refreshToken' => $accessToken->getRefreshToken(),
                            'expires' => $accessToken->getExpires(),
                            'baseDomain' => $baseDomain,
                        ]
                    );
                }
            );
    }

    public function hookHandler()
    {
        $result = [];
        try {
            if(isset($this->postData['leads'])) {
                if(isset($this->postData['leads']['add'])) {
                    $result = $this->afterAddHandler('leads');
                }elseif(isset($this->postData['leads']['update'])) {
                    $result = $this->afterUpdateHandler('leads');
                }
            }

            if(isset($this->postData['contacts'])) {
                if(isset($this->postData['contacts']['add'])) {
                    $result = $this->afterAddHandler('contacts');
                }elseif(isset($this->postData['contacts']['update'])) {
                    $result = $this->afterUpdateHandler('contacts');
                }
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }
        return $result;
    }

    /**
     * @throws InvalidArgumentException
     * @throws AmoCRMApiException
     * @throws AmoCRMMissedTokenException
     * @throws AmoCRMoAuthApiException
     */
    private function afterAddHandler($entity)
    {
        //текстовое примечание должно содержать название сделки/контакта, ответственного и время добавления карточки
        $result = [];
        foreach ($this->postData[$entity]['add'] as $event) {
            $users = $this->apiClient->users()->getOne($event['modified_user_id']);
            $user = $users->toArray();

            $notesCollection = new NotesCollection();
            $text = $event['name'] . PHP_EOL . 'Создал: ' . $user['name'] . PHP_EOL;
            $time = date('d-m-Y H:i:s', $event['created_at']);
            $text .= ' Дата и время: ' . $time;

            $commonNote = new CommonNote();
            $commonNote->setEntityId($event['id'])
                ->setText($text)
                ->setCreatedBy($event['modified_user_id']);
            $notesCollection->add($commonNote);

            $notesService = $this->apiClient->notes($entity);
            $result['notes'] = $notesService->add($notesCollection);
        }
        return $result;
    }

    /**
     * @throws AmoCRMApiException
     * @throws AmoCRMoAuthApiException
     * @throws AmoCRMMissedTokenException
     */
    private function afterUpdateHandler($entity)
    {
        //текстовое примечание должно содержать названия и новые значения измененных полей, время изменения карточки
        $result = [];
        foreach ($this->postData[$entity]['update'] as $event) {
            $eventsFilter = new EventsFilter();
            $eventsFilter->setEntity([$entity]);
            $eventsFilter->setEntityIds([$event['id']]);
            $eventsFilter->setCreatedAt([$event['updated_at']]);

            $events = $this->apiClient->events()->get($eventsFilter);
            $eventsResult = $events->toArray();

            $notesCollection = new NotesCollection();
            foreach ($eventsResult as $eventItem) {
                if($eventItem['type'] === 'common_note_added') continue;

                $text = '';
                foreach ($eventItem['value_after'] as $key => $data) {
                    foreach ($data as $nameField => $valueField) {
                        $text .= 'Поле: ' . $nameField;
                        if (isset($eventItem['value_before'][$key][$nameField])) {
                            $text .= ' Было: ' . array_values($eventItem['value_before'][$key][$nameField])[0];
                        }
                        $text .= ' Стало: ' . array_values($valueField)[0] . PHP_EOL;
                    }
                }
                $time = date('d-m-Y H:i:s', $eventItem['created_at']);
                $text .= ' Дата и время: ' . $time;

                $commonNote = new CommonNote();
                $commonNote->setEntityId($eventItem['entity_id'])
                    ->setText($text)
                    ->setCreatedBy($eventItem['created_by']);
                $notesCollection->add($commonNote);
            }

            $notesService = $this->apiClient->notes($entity);
            $result['notes'] = $notesService->add($notesCollection);

        }

        return $result;
    }
}
