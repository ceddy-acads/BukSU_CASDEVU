-- =====================================================================
-- CASMS - Update the category colours
--
-- For a database created before the calendar redesign. A fresh install
-- from database/schema.sql already has these colours:
--
--   C:\xampp\mysql\bin\mysql.exe -u root casms < database/update-category-colours.sql
--
-- Replaces the five original stock colours with ones that sit beside BukSU
-- navy and gold. A colour an administrator has already changed (Admin >
-- Categories) is left as it is: each row changes only while it still has
-- its original value.
--
-- Safe to re-run.
-- =====================================================================

UPDATE activity_categories SET color_hex = '#7B3F78' WHERE name = 'Culture'     AND color_hex = '#8E44AD';
UPDATE activity_categories SET color_hex = '#B5552B' WHERE name = 'Arts'        AND color_hex = '#E67E22';
UPDATE activity_categories SET color_hex = '#2E7D52' WHERE name = 'Sports'      AND color_hex = '#27AE60';
UPDATE activity_categories SET color_hex = '#1F6F8B' WHERE name = 'Intramurals' AND color_hex = '#2980B9';
UPDATE activity_categories SET color_hex = '#5F6B7A' WHERE name = 'Others'      AND color_hex = '#7F8C8D';
