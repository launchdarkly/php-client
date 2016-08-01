<?php
namespace LaunchDarkly;

/**
 * Contains specific attributes of a user browsing your site. The only mandatory property property is the key,
 * which must uniquely identify each user. For authenticated users, this may be a username or e-mail address. For anonymous users,
 * this could be an IP address or session ID.
 */
class LDUser {
    protected $_key = null;
    protected $_secondary = null;
    protected $_ip = null;
    protected $_country = null;
    protected $_email = null;
    protected $_name = null;
    protected $_avatar = null;
    protected $_firstName = null;
    protected $_lastName = null;
    protected $_anonyomus = false;
    protected $_custom = array();

    /**
     * @param string $key Unique key for the user. For authenticated users, this may be a username or e-mail address. For anonymous users, this could be an IP address or session ID.
     * @param string|null $secondary An optional secondary identifier
     * @param string|null $ip The user's IP address (optional)
     * @param string|null $country The user's country, as an ISO 3166-1 alpha-2 code (e.g. 'US') (optional)
     * @param string|null $email The user's e-mail address (optional)
     * @param string|null $name The user's full name (optional)
     * @param string|null $avatar A URL pointing to the user's avatar image (optional)
     * @param string|null $firstName The user's first name (optional)
     * @param string|null $lastName The user's last name (optional)
     * @param boolean|null $anonymous Whether this is an anonymous user
     * @param array|null $custom Other custom attributes that can be used to create custom rules
     */
    public function __construct($key, $secondary = null, $ip = null, $country = null, $email = null, $name = null, $avatar = null, $firstName = null, $lastName = null, $anonymous = null, $custom = array()) {
        $this->_key = strval($key);
        $this->_secondary = $secondary;
        $this->_ip = $ip;
        $this->_country = $country;
        $this->_email = $email;
        $this->_name = $name;
        $this->_avatar = $avatar;
        $this->_firstName = $firstName;
        $this->_lastName = $lastName;
        $this->_anonymous = $anonymous;
        $this->_custom = $custom;
    }

    public function getValueForEvaluation($attr) {
        switch ($attr) {
            case "key":
                return $this->getKey();
            case "secondary": //not available for evaluation.
                return null;
            case "ip":
                return $this->getIP();
            case "country":
                return $this->getCountry();
            case "email":
                return $this->getEmail();
            case "name":
                return $this->getName();
            case "avatar":
                return $this->getAvatar();
            case "firstName":
                return $this->getFirstName();
            case "lastName":
                return $this->getLastName();
            case "anonymous":
                return $this->getAnonymous();
            default:
                $custom = $this->getCustom();
                if (is_null($custom)) {
                    return null;
                }
                if (!array_key_exists($attr, $custom)) {
                    return null;
                }
                return $custom[$attr];
        }
    }

    public function getCountry() {
        return $this->_country;
    }

    public function getCustom() {
        return $this->_custom;
    }

    public function getIP() {
        return $this->_ip;
    }

    public function getKey() {
        return $this->_key;
    }

    public function getSecondary() {
        return $this->_secondary;
    }

    public function getEmail() {
        return $this->_email;
    }

    public function getName() {
        return $this->_name;
    }

    public function getAvatar() {
        return $this->_avatar;
    }

    public function getFirstName() {
        return $this->_firstName;
    }

    public function getLastName() {
        return $this->_lastName;
    }

    public function getAnonymous() {
        return $this->_anonymous;
    }

    public function toJSON() {
        $json = array("key" => $this->_key);

        if (isset($this->_secondary)) {
            $json['secondary'] = $this->_secondary;
        }
        if (isset($this->_ip)) {
            $json['ip'] = $this->_ip;
        }
        if (isset($this->_country)) {
            $json['country'] = $this->_country;
        }
        if (isset($this->_email)) {
            $json['email'] = $this->_email;
        }
        if (isset($this->_name)) {
            $json['name'] = $this->_name;
        }
        if (isset($this->_avatar)) {
            $json['avatar'] = $this->_avatar;
        }
        if (isset($this->_firstName)) {
            $json['firstName'] = $this->_firstName;
        }
        if (isset($this->_lastName)) {
            $json['lastName'] = $this->_lastName;
        }
        if (isset($this->_custom) && !empty($this->_custom)) {
            $json['custom'] = $this->_custom;
        }
        if (isset($this->_anonymous)) {
            $json['anonymous'] = $this->_anonymous;
        }
        return $json;
    }
}
