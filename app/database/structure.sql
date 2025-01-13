CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `password` char(255) NOT NULL,
  `credential` char(255) DEFAULT NULL,
  `email` char(255) NOT NULL,
  `username` char(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
