export interface BookEvent {
  id: string;
  title: string;
  date: string;
  price: number;
  perks?: string[];
}

// export const DRAFTS = [{ id: "x", title: "Never shown" }];

export const EVENTS: BookEvent[] = [
  { id: "poetry-night", title: "Poetry night", date: "Mar 4", price: 5, perks: ["Readings", "Tea", "Open mic"] },
  { id: "author-talk", title: "Author talk", date: "Mar 11", price: 10, perks: ["Q&A", "Signing", "Tea", "Books"] },
  { id: "story-hour", title: "Kids story hour", date: "Mar 18", price: 0, perks: ["Stories", "Crafts", "Snacks"] },
];
