<?php

namespace AppBundle\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use FOS\UserBundle\Model\User as BaseUser;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * @ORM\Entity
 * @ApiResource
 */
class ApiUser extends BaseUser
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\ManyToMany(targetEntity="Restaurant")
     * @ORM\JoinTable(inverseJoinColumns={@ORM\JoinColumn()})
     */
    private $restaurants;

    /**
     * @ORM\OneToMany(targetEntity="DeliveryAddress", mappedBy="customer")
     */
    private $deliveryAddresses;

    public function __construct()
    {
        $this->deliveryAddresses = new ArrayCollection();
    }

    public function setRestaurants($restaurants)
    {
        $this->restaurants = $restaurants;

        return $this;
    }

    public function getRestaurants()
    {
        return $this->restaurants;
    }

    public function setDeliveryAddresses($deliveryAddresses)
    {
        $this->deliveryAddresses = $deliveryAddresses;

        return $this;
    }

    public function getDeliveryAddresses()
    {
        return $this->deliveryAddresses;
    }
}