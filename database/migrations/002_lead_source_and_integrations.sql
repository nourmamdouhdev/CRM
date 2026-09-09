-- Additive upgrade for existing Tagom CRM databases.
-- Safe to run more than once if you skip statements that already applied.

USE `tagom_crm`;

ALTER TABLE `parties`
  ADD COLUMN `lead_source` ENUM(
    'marketing_campaign',
    'cold_lead',
    'business_cards',
    'marketing_agent',
    'people_referral'
  ) DEFAULT NULL AFTER `notes`;

ALTER TABLE `parties`
  ADD KEY `idx_parties_lead_source` (`lead_source`);

CREATE TABLE IF NOT EXISTS `integration_settings` (
  `setting_key` VARCHAR(80) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `direction` ENUM('out', 'in') NOT NULL,
  `phone` VARCHAR(32) NOT NULL,
  `wa_message_id` VARCHAR(128) DEFAULT NULL,
  `body` TEXT NOT NULL,
  `status` VARCHAR(40) NOT NULL DEFAULT 'queued',
  `error_message` VARCHAR(255) DEFAULT NULL,
  `payload_json` JSON DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wa_party` (`party_id`),
  KEY `idx_wa_phone` (`phone`),
  KEY `idx_wa_created` (`created_at`),
  KEY `idx_wa_wa_message_id` (`wa_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
