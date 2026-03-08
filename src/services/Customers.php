<?php

namespace justinholtweb\stub\services;

use Craft;
use craft\db\Query;
use justinholtweb\stub\models\Customer;
use justinholtweb\stub\records\CustomerRecord;
use yii\base\Component;

class Customers extends Component
{
    public function getCustomerById(int $id): ?Customer
    {
        $row = $this->_createQuery()
            ->where(['id' => $id])
            ->one();

        return $row ? $this->_createCustomerFromRow($row) : null;
    }

    public function getCustomerByEmail(string $email): ?Customer
    {
        $row = $this->_createQuery()
            ->where(['email' => $email])
            ->one();

        return $row ? $this->_createCustomerFromRow($row) : null;
    }

    public function getAllCustomers(): array
    {
        return array_map(
            fn($row) => $this->_createCustomerFromRow($row),
            $this->_createQuery()->orderBy(['dateCreated' => SORT_DESC])->all()
        );
    }

    public function findOrCreate(string $email, string $firstName, string $lastName, ?string $phone = null): Customer
    {
        $existing = $this->getCustomerByEmail($email);
        if ($existing) {
            // Update name if changed
            if ($existing->firstName !== $firstName || $existing->lastName !== $lastName) {
                $existing->firstName = $firstName;
                $existing->lastName = $lastName;
                if ($phone) {
                    $existing->phone = $phone;
                }
                $this->saveCustomer($existing);
            }
            return $existing;
        }

        $customer = new Customer([
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phone' => $phone,
        ]);

        // Link to existing Craft user if possible
        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($email);
        if ($user) {
            $customer->userId = $user->id;
        }

        $this->saveCustomer($customer);
        return $customer;
    }

    public function saveCustomer(Customer $customer): bool
    {
        if (!$customer->validate()) {
            return false;
        }

        if ($customer->id) {
            $record = CustomerRecord::findOne($customer->id);
            if (!$record) {
                return false;
            }
        } else {
            $record = new CustomerRecord();
        }

        $record->userId = $customer->userId;
        $record->email = $customer->email;
        $record->firstName = $customer->firstName;
        $record->lastName = $customer->lastName;
        $record->phone = $customer->phone;
        $record->notes = $customer->notes;

        if (!$record->save()) {
            $customer->addErrors($record->getErrors());
            return false;
        }

        $customer->id = $record->id;
        $customer->uid = $record->uid;
        $customer->dateCreated = $record->dateCreated;
        $customer->dateUpdated = $record->dateUpdated;

        return true;
    }

    public function getBookingsForCustomer(int $customerId): array
    {
        return \justinholtweb\stub\elements\Booking::find()
            ->customerId($customerId)
            ->orderBy(['startDateTime' => SORT_DESC])
            ->all();
    }

    public function searchCustomers(string $query): array
    {
        return array_map(
            fn($row) => $this->_createCustomerFromRow($row),
            $this->_createQuery()
                ->where(['or',
                    ['like', 'email', $query],
                    ['like', 'firstName', $query],
                    ['like', 'lastName', $query],
                    ['like', 'phone', $query],
                ])
                ->orderBy(['dateCreated' => SORT_DESC])
                ->limit(50)
                ->all()
        );
    }

    private function _createQuery(): Query
    {
        return (new Query())
            ->select(['id', 'userId', 'email', 'firstName', 'lastName', 'phone', 'notes', 'dateCreated', 'dateUpdated', 'uid'])
            ->from('{{%stub_customers}}');
    }

    private function _createCustomerFromRow(array $row): Customer
    {
        return new Customer([
            'id' => (int)$row['id'],
            'userId' => $row['userId'] ? (int)$row['userId'] : null,
            'email' => $row['email'],
            'firstName' => $row['firstName'],
            'lastName' => $row['lastName'],
            'phone' => $row['phone'],
            'notes' => $row['notes'],
            'dateCreated' => $row['dateCreated'],
            'dateUpdated' => $row['dateUpdated'],
            'uid' => $row['uid'],
        ]);
    }
}
