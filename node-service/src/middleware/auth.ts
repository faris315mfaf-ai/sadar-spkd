import { Request, Response, NextFunction } from 'express';
import config from '../config';
import { UnauthorizedError } from '../types';

export const verifyBotToken = (
  req: Request,
  _res: Response,
  next: NextFunction
): void => {
  const authHeader = req.headers.authorization;

  if (!config.botToken) {
    throw new UnauthorizedError('BOT_TOKEN is not configured');
  }

  if (authHeader !== `Bearer ${config.botToken}`) {
    throw new UnauthorizedError('Invalid bot token');
  }

  next();
};

export const authenticatePdfToken = (
  req: Request,
  _res: Response,
  next: NextFunction
): void => {
  // Fail closed like the bot route: an unset token must not open the PDF renderer to everyone.
  if (!config.authToken) {
    throw new UnauthorizedError('AUTH_TOKEN is not configured');
  }

  const authHeader = req.headers['authorization'];
  const token = authHeader && authHeader.split(' ')[1]; // Bearer TOKEN

  if (!token) {
    throw new UnauthorizedError('Authorization token is required');
  }

  if (token !== config.authToken) {
    throw new UnauthorizedError('Invalid authorization token');
  }

  next();
};
