export type CreateJobInput = {
  keyword: string;
  zips: string[];
  source?: 'playwright';
  createdBy?: string;
  filters?: {
    maxReviews?: number;
    requireWebsite?: boolean;
    requirePhone?: boolean;
  };
};

export type MapsBusiness = {
  sourcePlaceId?: string;
  name: string;
  phone?: string;
  website?: string;
  address?: string;
  city?: string;
  state?: string;
  zip?: string;
  rating?: number;
  reviewCount?: number;
  googleMapsUrl?: string;
  raw?: unknown;
};
