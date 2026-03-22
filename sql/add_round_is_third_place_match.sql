-- One-time migration: persist 3rd-place matchup on round rows.
-- Run against existing databases. New installs can rely on database.sql only.
-- After it has been applied everywhere you care about, delete this file so it is not run again by mistake.
ALTER TABLE `round`
  ADD COLUMN `round_is_third_place_match` tinyint(1) NOT NULL DEFAULT 0 AFTER `round_deleted`;

-- Backfill: same tier/group as a championship row (order 0), real opponent, tournament tier.
UPDATE `round` r
SET r.`round_is_third_place_match` = 1
WHERE r.`round_deleted` = 0
  AND r.`round_tier` > 1
  AND r.`round_order` = 1
  AND r.`round_character2_id` > 1
  AND EXISTS (
    SELECT 1 FROM `round` r0
    WHERE r0.`bracket_id` = r.`bracket_id`
      AND r0.`round_tier` = r.`round_tier`
      AND r0.`round_group` = r.`round_group`
      AND r0.`round_order` = 0
      AND r0.`round_deleted` = 0
  );
