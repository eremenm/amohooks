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
        $this->postData = $_POST ?: $input;

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
                    AmoCRMTokenActions::saveToken(
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
            //TODO Про setCreatedAt Этот фильтр не идеален, бывают ситуации когда время самого события отличается на секунду.
            // Как вариант, записывать в файл, например, события по которым уже было создано примечание,
            // а потом получать все события элемента и отфильтровывать их.
            // Но для тестового задания я подумал что это уже слишком ;)
            $eventsFilter->setCreatedAt([$event['updated_at']]);

            $events = $this->apiClient->events()->get($eventsFilter);
            $eventsResult = $events->toArray();
            $eventsResult = $this->eventsParse($eventsResult, $entity);

            $notesCollection = new NotesCollection();
            foreach ($eventsResult as $eventItem) {
                $commonNote = new CommonNote();
                $commonNote->setEntityId($eventItem['entity_id'])
                    ->setText($eventItem['text'])
                    ->setCreatedBy($eventItem['created_by']);
                $notesCollection->add($commonNote);
            }
            $notesService = $this->apiClient->notes($entity);
            $result['notes'] = $notesService->add($notesCollection);
        }

        return $result;
    }

    private function eventsParse($events, $entity)
    {
        $result = [];
        foreach ($events as $eventItem) {
            if($eventItem['type'] === 'common_note_added') continue;

            //TODO Тут надо обработать все варианты событий, с разными вариациями структуры данных и запросом других сущностей.
            // Но для тестового задания это тоже уже слишком, можно просидеть пару дней прежде чем это заработает как надо.
            // В итоге обработал несколько типов полей.
            $text = '';
            if (preg_match('/^custom_field_\d+_value_changed$/', $eventItem['type'])) {
                $customFieldsService = $this->apiClient->customFields($entity);
                $fieldsData = $customFieldsService->get();
                $fieldsName = [];
                foreach ($fieldsData as $field) $fieldsName[$field->id] = $field->name;

                $fieldName = '';
                if (!empty($eventItem['value_before'])) {
                    foreach ($eventItem['value_before'] as $valueBefore) {
                        $fieldName = $fieldsName[$valueBefore['custom_field_value']['field_id']];
                    }
                }
                if (!empty($eventItem['value_after'])) {
                    foreach ($eventItem['value_after'] as $valueAfter) {
                        $fieldName = $fieldsName[$valueAfter['custom_field_value']['field_id']];
                        $fieldValue = $valueAfter['custom_field_value']['text'];
                        $text .= 'Поле "' . $fieldName . '" стало: ' . $fieldValue . PHP_EOL;
                    }
                } else {
                    $text .= 'Поле ' . $fieldName . ' стало: "пусто"' . PHP_EOL;
                }
            }
            if ($eventItem['type'] === 'entity_tag_added') {
                foreach ($eventItem['value_after'] as $valueAfter) {
                    $text .= 'Был добавлен тег: ' . $valueAfter['tag']['name'] . PHP_EOL;
                }
            }
            if ($eventItem['type'] === 'entity_tag_deleted') {
                foreach ($eventItem['value_after'] as $valueAfter) {
                    $text .= 'Был удален тег: ' . $valueAfter['tag']['name'] . PHP_EOL;
                }
            }
            if ($eventItem['type'] === 'name_field_changed') {
                foreach ($eventItem['value_after'] as $valueAfter) {
                    $text .= 'Название стало: ' . $valueAfter['name_field_value']['name'] . PHP_EOL;
                }
            }
            if ($eventItem['type'] === 'sale_field_changed') {
                foreach ($eventItem['value_after'] as $valueAfter) {
                    $text .= 'Бюджет стал: ' . $valueAfter['sale_field_value']['sale'] . PHP_EOL;
                }
            }

            $time = date('d-m-Y H:i:s', $eventItem['created_at']);
            $text .= 'Дата и время: ' . $time;

            $result[] = [
                'entity_id' => $eventItem['entity_id'],
                'text' => $text,
                'created_by' => $eventItem['created_by']
            ];
        }
        return $result;
    }
}
